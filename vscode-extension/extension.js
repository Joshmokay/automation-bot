let vscode;
try {
    vscode = require("vscode");
} catch (_) {
    vscode = null;
}
const http = require("http");
const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

// ======================================================
// GITTYPE BACKGROUND ENGINE
// ======================================================

const HOST = "127.0.0.1";
const DEFAULT_PORT = 54321;
const MAX_BODY = 10 * 1024 * 1024; // 10 MB
const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB per file
const DEFAULT_CHUNK_SIZE = 16;

let server = null;
let activeSockets = new Set();
let token = null;
let bridgeFile = null;
let projectRoot = null;

let active = false;
let isTyping = false;
let stopRequested = false;
let pauseRequested = false;

let currentJob = null;
let jobQueue = [];
let jobHistory = [];

// ======================================================
// UTILITIES & HELPERS
// ======================================================

function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, Math.max(0, ms)));
}

function normalizeLines(value) {
    if (typeof value !== "string") return "";
    return value.replace(/\r\n/g, "\n").replace(/\r/g, "\n");
}

function sendResponse(res, status, data) {
    if (!res || res.writableEnded) return;

    try {
        const payload = JSON.stringify(data);
        res.writeHead(status, {
            "Content-Type": "application/json; charset=utf-8",
            "Content-Length": Buffer.byteLength(payload, "utf8"),
            "Cache-Control": "no-store, no-cache, must-revalidate",
            "Connection": "keep-alive"
        });
        res.end(payload);
    } catch (err) {
        console.error("GitType error sending response:", err);
    }
}

function readBody(req) {
    return new Promise((resolve, reject) => {
        let body = "";
        let size = 0;

        req.on("data", chunk => {
            size += chunk.length;
            if (size > MAX_BODY) {
                const err = new Error("Request body exceeds maximum allowed size (10 MB).");
                err.statusCode = 413;
                reject(err);
                req.destroy();
                return;
            }
            body += chunk.toString("utf8");
        });

        req.on("end", () => {
            if (!body.trim()) {
                resolve({});
                return;
            }
            try {
                resolve(JSON.parse(body));
            } catch {
                const err = new Error("Invalid JSON in request body.");
                err.statusCode = 400;
                reject(err);
            }
        });

        req.on("error", err => {
            reject(err);
        });
    });
}

// ======================================================
// AUTHENTICATION
// ======================================================

function authenticate(req) {
    const supplied = req.headers["x-gittype-token"];

    if (typeof supplied !== "string" || !token) {
        return false;
    }

    const expectedBuffer = Buffer.from(token, "utf8");
    const suppliedBuffer = Buffer.from(supplied, "utf8");

    if (expectedBuffer.length !== suppliedBuffer.length) {
        return false;
    }

    try {
        return crypto.timingSafeEqual(expectedBuffer, suppliedBuffer);
    } catch {
        return false;
    }
}

// ======================================================
// PATH VALIDATION & WORKSPACE BOUNDARY ENFORCEMENT
// ======================================================

function getWorkspaceRoot(context) {
    if (vscode && vscode.workspace && vscode.workspace.workspaceFolders && vscode.workspace.workspaceFolders.length > 0) {
        return path.resolve(vscode.workspace.workspaceFolders[0].uri.fsPath);
    }
    if (context && context.extensionPath) {
        const candidate = path.resolve(context.extensionPath, "..");
        if (fs.existsSync(path.join(candidate, "main.py")) || fs.existsSync(path.join(candidate, "core"))) {
            return candidate;
        }
    }
    const defaultGitType = path.resolve(process.env.USERPROFILE || "C:\\Users\\Admin", "Documents", "GitType");
    if (fs.existsSync(defaultGitType)) {
        return defaultGitType;
    }
    return process.cwd();
}

function validateDestination(filePath) {
    if (typeof filePath !== "string" || !filePath.trim()) {
        const err = new Error("Invalid or empty destination path.");
        err.statusCode = 400;
        throw err;
    }

    const resolvedRoot = path.resolve(projectRoot);
    const destination = path.isAbsolute(filePath)
        ? path.resolve(filePath)
        : path.resolve(resolvedRoot, filePath);

    const relative = path.relative(resolvedRoot, destination);

    // Prevent path traversal outside project root
    if (
        relative === "" ||
        relative.startsWith(".." + path.sep) ||
        relative === ".." ||
        path.isAbsolute(relative)
    ) {
        const err = new Error("Destination must be located inside the GitType workspace.");
        err.statusCode = 400;
        throw err;
    }

    // Reject symbolic links in path or file
    if (fs.existsSync(destination)) {
        try {
            const stat = fs.lstatSync(destination);
            if (stat.isSymbolicLink()) {
                const err = new Error("Symbolic link destinations are not allowed.");
                err.statusCode = 400;
                throw err;
            }
            if (!stat.isFile()) {
                const err = new Error("Destination is not a regular file.");
                err.statusCode = 400;
                throw err;
            }
        } catch (e) {
            if (e.statusCode) throw e;
        }
    }

    return destination;
}

// ======================================================
// DOCUMENT EDITING OPERATIONS (TRUE BACKGROUND EDITING)
// ======================================================

async function processSingleFile(fileConfig, jobOptions) {
    const destination = validateDestination(fileConfig.path);
    const content = typeof fileConfig.content === "string" ? fileConfig.content : "";
    const allowOverwrite = fileConfig.overwrite === true || jobOptions.overwrite === true;
    const mode = jobOptions.mode || "incremental";
    const delayMs = typeof jobOptions.delay === "number" ? jobOptions.delay : 30;

    if (Buffer.byteLength(content, "utf8") > MAX_FILE_SIZE) {
        const err = new Error(`File ${fileConfig.path} exceeds maximum file size limit (5 MB).`);
        err.statusCode = 400;
        throw err;
    }

    const parentDir = path.dirname(destination);
    fs.mkdirSync(parentDir, { recursive: true });

    // Overwrite safety check
    if (fs.existsSync(destination)) {
        const currentSize = fs.statSync(destination).size;
        if (currentSize > 0 && !allowOverwrite) {
            const err = new Error(`Destination already exists and contains data: ${fileConfig.path}. Set overwrite=true to replace.`);
            err.statusCode = 409;
            throw err;
        }
    } else {
        // Create empty file
        fs.writeFileSync(destination, "", { encoding: "utf8" });
    }

    // Open text document in background without opening/focusing an editor tab
    const document = await vscode.workspace.openTextDocument(vscode.Uri.file(destination));

    if (mode === "fast" || delayMs <= 0) {
        // Fast document insertion mode
        const edit = new vscode.WorkspaceEdit();
        const currentText = document.getText();

        if (currentText.length > 0) {
            const fullRange = new vscode.Range(
                document.positionAt(0),
                document.positionAt(currentText.length)
            );
            edit.replace(document.uri, fullRange, content);
        } else {
            edit.insert(document.uri, new vscode.Position(0, 0), content);
        }

        const success = await vscode.workspace.applyEdit(edit);
        if (!success) {
            throw new Error(`VS Code rejected the document edit for ${fileConfig.path}.`);
        }
    } else {
        // Human-like incremental insertion mode
        // Split text cleanly by Unicode characters (handles surrogate pairs and emoji)
        const characters = Array.from(content);
        const totalChars = characters.length;
        let charIndex = 0;

        // Clear existing content if overwrite requested and file had text
        const lastLineIdx = Math.max(0, document.lineCount - 1);
        const lastLineLen = document.lineAt(lastLineIdx).text.length;
        if (lastLineIdx > 0 || lastLineLen > 0) {
            const clearEdit = new vscode.WorkspaceEdit();
            const fullRange = new vscode.Range(
                new vscode.Position(0, 0),
                new vscode.Position(lastLineIdx, lastLineLen)
            );
            clearEdit.delete(document.uri, fullRange);
            await vscode.workspace.applyEdit(clearEdit);
        }

        const effectiveChunkSize = Math.max(1, Math.min(jobOptions.chunkSize || DEFAULT_CHUNK_SIZE, 128));

        while (charIndex < totalChars) {
            if (stopRequested) {
                return {
                    success: false,
                    stopped: true,
                    file: destination,
                    relative_path: path.relative(projectRoot, destination),
                    characters: charIndex,
                    total_characters: totalChars,
                    verified: false,
                    message: `Typing stopped by user at character ${charIndex}/${totalChars}.`
                };
            }

            while (pauseRequested && !stopRequested) {
                await delay(100);
            }

            if (stopRequested) {
                return {
                    success: false,
                    stopped: true,
                    file: destination,
                    relative_path: path.relative(projectRoot, destination),
                    characters: charIndex,
                    total_characters: totalChars,
                    verified: false,
                    message: `Typing stopped by user at character ${charIndex}/${totalChars}.`
                };
            }

            const chunkChars = characters.slice(charIndex, charIndex + effectiveChunkSize);
            const chunkStr = chunkChars.join("");

            const edit = new vscode.WorkspaceEdit();
            const curLastLine = Math.max(0, document.lineCount - 1);
            const curLastLen = document.lineAt(curLastLine).text.length;
            const insertPos = new vscode.Position(curLastLine, curLastLen);
            edit.insert(document.uri, insertPos, chunkStr);

            const success = await vscode.workspace.applyEdit(edit);
            if (!success) {
                throw new Error(`VS Code rejected edit at character ${charIndex} in ${fileConfig.path}.`);
            }

            charIndex += chunkChars.length;

            if (currentJob) {
                currentJob.current_file_characters = charIndex;
                currentJob.current_file_total_characters = totalChars;
            }

            if (delayMs > 0) {
                await delay(delayMs);
            }
        }
    }

    // Save document through VS Code
    const saved = await document.save();
    if (!saved) {
        throw new Error(`VS Code could not save the document: ${fileConfig.path}`);
    }

    // Verify written content with normalized line endings
    const savedContent = fs.readFileSync(destination, "utf8");
    if (normalizeLines(savedContent) !== normalizeLines(content)) {
        throw new Error(`Verification mismatch: saved file content does not match expected input for ${fileConfig.path}.`);
    }

    return {
        success: true,
        file: destination,
        relative_path: path.relative(projectRoot, destination),
        characters: content.length,
        bytes: Buffer.byteLength(content, "utf8"),
        verified: true
    };
}

// ======================================================
// JOB RUNNER
// ======================================================

async function executeJob(job) {
    isTyping = true;
    active = true;
    stopRequested = false;
    pauseRequested = false;
    currentJob = job;

    const results = [];
    let jobError = null;

    try {
        for (let i = 0; i < job.files.length; i++) {
            if (stopRequested) {
                break;
            }

            const fileItem = job.files[i];
            currentJob.current_file_index = i;
            currentJob.current_file_path = fileItem.path;
            currentJob.current_file_characters = 0;
            currentJob.current_file_total_characters = (fileItem.content || "").length;

            try {
                const fileResult = await processSingleFile(fileItem, job.options);
                results.push(fileResult);
                if (fileResult.success) {
                    currentJob.completed_files.push(fileItem.path);
                } else if (fileResult.stopped) {
                    break;
                }
            } catch (fileErr) {
                currentJob.failed_files.push({
                    path: fileItem.path,
                    error: fileErr.message
                });
                if (job.options.stopOnError !== false) {
                    throw fileErr;
                }
            }
        }

        const isCancelled = stopRequested || results.some(r => r.stopped);
        const finalStatus = isCancelled ? "stopped" : "completed";

        const summary = {
            job_id: job.id,
            status: finalStatus,
            stopped: isCancelled,
            total_files: job.files.length,
            completed_files: results.filter(r => r.success).length,
            failed_files: currentJob.failed_files.length,
            files: results,
            success: !isCancelled && currentJob.failed_files.length === 0,
            completed_at: new Date().toISOString()
        };

        jobHistory.unshift(summary);
        if (jobHistory.length > 50) jobHistory.pop();

        return summary;

    } catch (err) {
        jobError = err;
        const failedSummary = {
            job_id: job.id,
            status: "failed",
            error: err.message,
            total_files: job.files.length,
            completed_files: results.length,
            failed_files: currentJob ? currentJob.failed_files : [],
            files: results,
            success: false,
            completed_at: new Date().toISOString()
        };
        jobHistory.unshift(failedSummary);
        if (jobHistory.length > 50) jobHistory.pop();
        throw err;
    } finally {
        isTyping = false;
        pauseRequested = false;
        stopRequested = false;
        currentJob = null;
    }
}

// ======================================================
// BACKGROUND HTTP BRIDGE SERVER
// ======================================================

function createBridgeServer() {
    const srv = http.createServer(async (req, res) => {
        // Error isolation for request processing
        try {
            // 1. HEALTH CHECK (no auth required)
            if (req.method === "GET" && req.url === "/health") {
                return sendResponse(res, 200, {
                    success: true,
                    service: "GitType Background Engine",
                    version: "1.0.0",
                    active: active || isTyping,
                    typing: isTyping,
                    paused: pauseRequested,
                    stopped: stopRequested,
                    current_job_id: currentJob ? currentJob.id : null,
                    queue_length: jobQueue.length,
                    workspace: projectRoot
                });
            }

            // 2. AUTHENTICATION CHECK
            if (!authenticate(req)) {
                return sendResponse(res, 401, {
                    success: false,
                    error: "Unauthorized: Invalid or missing X-GitType-Token."
                });
            }

            // 3. STATUS ENDPOINT
            if (req.method === "GET" && req.url === "/status") {
                return sendResponse(res, 200, {
                    success: true,
                    active: active || isTyping,
                    typing: isTyping,
                    paused: pauseRequested,
                    stopped: stopRequested,
                    workspace: projectRoot,
                    current_job: currentJob ? {
                        id: currentJob.id,
                        mode: currentJob.options.mode,
                        total_files: currentJob.files.length,
                        completed_files_count: currentJob.completed_files.length,
                        current_file_index: currentJob.current_file_index,
                        current_file_path: currentJob.current_file_path,
                        current_file_characters: currentJob.current_file_characters,
                        current_file_total_characters: currentJob.current_file_total_characters,
                        completed_files: currentJob.completed_files,
                        failed_files: currentJob.failed_files
                    } : null,
                    queue_length: jobQueue.length,
                    recent_jobs: jobHistory.slice(0, 5)
                });
            }

            // 4. PAUSE ENDPOINT
            if (req.method === "POST" && req.url === "/pause") {
                if (!isTyping) {
                    return sendResponse(res, 409, {
                        success: false,
                        error: "No active typing operation to pause."
                    });
                }
                pauseRequested = true;
                return sendResponse(res, 200, {
                    success: true,
                    paused: true,
                    message: "Background typing paused."
                });
            }

            // 5. RESUME ENDPOINT
            if (req.method === "POST" && req.url === "/resume") {
                pauseRequested = false;
                return sendResponse(res, 200, {
                    success: true,
                    paused: false,
                    message: "Background typing resumed."
                });
            }

            // 6. STOP ENDPOINT
            if (req.method === "POST" && req.url === "/stop") {
                stopRequested = true;
                pauseRequested = false;
                return sendResponse(res, 200, {
                    success: true,
                    stopping: true,
                    message: "Stop signal sent to background engine."
                });
            }

            // 7. CANCEL ENDPOINT
            if (req.method === "POST" && req.url === "/cancel") {
                stopRequested = true;
                pauseRequested = false;
                jobQueue = [];
                return sendResponse(res, 200, {
                    success: true,
                    cancelled: true,
                    message: "Current typing job stopped and queue cleared."
                });
            }

            // 8. TYPE / BATCH TYPE ENDPOINT
            if (req.method === "POST" && req.url === "/type") {
                if (isTyping) {
                    return sendResponse(res, 409, {
                        success: false,
                        error: "Another typing operation is currently in progress."
                    });
                }

                const data = await readBody(req);

                // Format files array: support both single-file legacy and multi-file structures
                let files = [];
                if (Array.isArray(data.files) && data.files.length > 0) {
                    files = data.files.map(f => ({
                        path: f.path || f.destination || f.file,
                        content: typeof f.content === "string" ? f.content : "",
                        overwrite: f.overwrite !== undefined ? f.overwrite : data.overwrite
                    }));
                } else if (data.path || data.destination || data.file) {
                    files = [{
                        path: data.path || data.destination || data.file,
                        content: typeof data.content === "string" ? data.content : "",
                        overwrite: data.overwrite === true
                    }];
                } else {
                    return sendResponse(res, 400, {
                        success: false,
                        error: "Invalid request: Provide either 'path' and 'content', or a 'files' array."
                    });
                }

                // Validate each file destination up-front
                for (const f of files) {
                    try {
                        validateDestination(f.path);
                    } catch (valErr) {
                        return sendResponse(res, valErr.statusCode || 400, {
                            success: false,
                            error: valErr.message
                        });
                    }
                }

                const job = {
                    id: data.job_id || ("job_" + crypto.randomBytes(8).toString("hex")),
                    files,
                    options: {
                        mode: data.mode || "incremental",
                        delay: typeof data.delay === "number" ? data.delay : (typeof data.speed === "number" ? data.speed : 30),
                        chunkSize: data.chunkSize || DEFAULT_CHUNK_SIZE,
                        overwrite: data.overwrite === true,
                        stopOnError: data.stopOnError !== false
                    },
                    current_file_index: 0,
                    current_file_path: null,
                    current_file_characters: 0,
                    current_file_total_characters: 0,
                    completed_files: [],
                    failed_files: []
                };

                const result = await executeJob(job);
                return sendResponse(res, 200, {
                    success: result.success,
                    stopped: result.stopped || false,
                    status: result.status,
                    job_id: job.id,
                    total_files: result.total_files,
                    completed_files: result.completed_files,
                    files: result.files,
                    // Backward-compatible fields for single-file tests
                    file: result.files[0] ? result.files[0].file : null,
                    characters: result.files[0] ? result.files[0].characters : 0,
                    verified: result.files[0] ? result.files[0].verified : (result.success && !result.stopped),
                    message: result.stopped ? "Typing was stopped by user request." : "All requested documents processed successfully."
                });
            }

            // Unknown endpoint
            return sendResponse(res, 404, {
                success: false,
                error: `Endpoint not found: ${req.method} ${req.url}`
            });

        } catch (error) {
            console.error("GitType bridge internal error:", error);
            const status = error.statusCode || 500;
            return sendResponse(res, status, {
                success: false,
                error: error.message || "Internal server error in GitType bridge."
            });
        }
    });

    // Track sockets for graceful termination
    srv.on("connection", socket => {
        activeSockets.add(socket);
        socket.on("close", () => activeSockets.delete(socket));
    });

    srv.keepAliveTimeout = 65000;
    srv.headersTimeout = 66000;

    return srv;
}

// ======================================================
// CONFIGURATION PERSISTENCE
// ======================================================

function writeBridgeConfig(port) {
    const config = {
        host: HOST,
        port: port,
        token: token,
        pid: process.pid,
        workspace: projectRoot,
        started_at: new Date().toISOString()
    };

    const tempFile = `${bridgeFile}.tmp.${Date.now()}`;

    try {
        fs.writeFileSync(tempFile, JSON.stringify(config, null, 2), {
            encoding: "utf8",
            mode: 0o600
        });

        if (fs.existsSync(bridgeFile)) {
            try {
                fs.rmSync(bridgeFile, { force: true });
            } catch (rmErr) {
                console.warn("Could not remove old bridge file immediately:", rmErr.message);
            }
        }

        fs.renameSync(tempFile, bridgeFile);
        return true;
    } catch (writeErr) {
        // Fallback: direct write if rename fails on Windows
        try {
            fs.writeFileSync(bridgeFile, JSON.stringify(config, null, 2), {
                encoding: "utf8",
                mode: 0o600
            });
            if (fs.existsSync(tempFile)) {
                try { fs.rmSync(tempFile, { force: true }); } catch (_) {}
            }
            return true;
        } catch (fbErr) {
            console.error("Fatal error writing bridge config:", fbErr);
            throw fbErr;
        }
    }
}

function removeBridgeConfig() {
    try {
        if (bridgeFile && fs.existsSync(bridgeFile)) {
            fs.rmSync(bridgeFile, { force: true });
        }
    } catch (err) {
        console.warn("Could not clean up bridge configuration file:", err.message);
    }
}

// ======================================================
// SERVER LIFECYCLE MANAGEMENT
// ======================================================

function stopServer() {
    stopRequested = true;
    pauseRequested = false;

    if (server) {
        try {
            server.close();
        } catch (_) {}
        server = null;
    }

    for (const socket of activeSockets) {
        try {
            socket.destroy();
        } catch (_) {}
    }
    activeSockets.clear();

    removeBridgeConfig();
    active = false;
    isTyping = false;
}

function startServer(context) {
    stopServer(); // Ensure no previous server remains active

    projectRoot = getWorkspaceRoot(context);
    bridgeFile = path.join(projectRoot, ".gittype-bridge.json");
    token = crypto.randomBytes(32).toString("hex");

    let preferredPort = DEFAULT_PORT;
    try {
        if (vscode.workspace && vscode.workspace.getConfiguration) {
            preferredPort = vscode.workspace.getConfiguration("gittype").get("bridgePort", DEFAULT_PORT);
        }
    } catch (_) {}

    server = createBridgeServer();

    function attemptListen(port, isFallback = false) {
        server.removeAllListeners("error");

        server.on("error", err => {
            if (err.code === "EADDRINUSE" && !isFallback) {
                console.warn(`GitType preferred port ${port} is in use. Falling back to automatic port selection.`);
                attemptListen(0, true);
                return;
            }

            console.error("GitType bridge server error:", err);
            if (vscode && vscode.window) {
                vscode.window.showErrorMessage(`GitType bridge server error: ${err.message}`);
            }
            stopServer();
        });

        server.listen(port, HOST, () => {
            const address = server.address();
            const boundPort = address.port;

            try {
                writeBridgeConfig(boundPort);
                active = true;
                console.log(`GitType Background Engine listening on http://${HOST}:${boundPort}`);
                if (vscode && vscode.window) {
                    vscode.window.showInformationMessage(`GitType Background Engine is ready on port ${boundPort}`);
                }
            } catch (cfgErr) {
                if (vscode && vscode.window) {
                    vscode.window.showErrorMessage("GitType could not write its bridge configuration.");
                }
                stopServer();
            }
        });
    }

    attemptListen(preferredPort, false);
}

// ======================================================
// EXTENSION ACTIVATION & DEACTIVATION
// ======================================================

function activate(context) {
    console.log("GitType Background Engine activating...");

    startServer(context);

    if (vscode && vscode.commands) {
        const startCmd = vscode.commands.registerCommand("gittype.start", () => {
            startServer(context);
        });

        const stopCmd = vscode.commands.registerCommand("gittype.stop", () => {
            stopRequested = true;
            pauseRequested = false;
            if (vscode.window) {
                vscode.window.showInformationMessage("GitType: Background typing stop requested.");
            }
        });

        const pauseCmd = vscode.commands.registerCommand("gittype.pause", () => {
            if (isTyping) {
                pauseRequested = true;
                if (vscode.window) {
                    vscode.window.showInformationMessage("GitType: Background typing paused.");
                }
            } else {
                if (vscode.window) {
                    vscode.window.showWarningMessage("GitType: No active typing operation to pause.");
                }
            }
        });

        const resumeCmd = vscode.commands.registerCommand("gittype.resume", () => {
            pauseRequested = false;
            if (vscode.window) {
                vscode.window.showInformationMessage("GitType: Background typing resumed.");
            }
        });

        const statusCmd = vscode.commands.registerCommand("gittype.status", () => {
            const stateStr = isTyping
                ? (pauseRequested ? "Paused" : "Typing")
                : "Idle";
            const fileInfo = currentJob && currentJob.current_file_path
                ? ` | File: ${currentJob.current_file_path}`
                : "";
            if (vscode.window) {
                vscode.window.showInformationMessage(`GitType Status: ${stateStr}${fileInfo}`);
            }
        });

        context.subscriptions.push(startCmd, stopCmd, pauseCmd, resumeCmd, statusCmd);
    }

    if (context && context.subscriptions) {
        context.subscriptions.push({
            dispose: () => {
                stopServer();
            }
        });
    }
}

function deactivate() {
    stopServer();
    console.log("GitType Background Engine deactivated.");
}

function setVSCodeMock(customVSCode) {
    vscode = customVSCode;
}

module.exports = {
    activate,
    deactivate,
    setVSCodeMock,
    // Export internals for test harnesses
    _startServer: startServer,
    _stopServer: stopServer,
    _executeJob: executeJob,
    _validateDestination: validateDestination,
    _createBridgeServer: createBridgeServer
};
