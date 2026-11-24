import json
import time
import urllib.request
import urllib.error
from pathlib import Path
from typing import Optional, Dict, Any, List, Union, Callable


# =====================================================================
# CUSTOM EXCEPTIONS
# =====================================================================

class BridgeError(Exception):
    """Base exception for GitType background bridge errors."""
    pass


class BridgeNotRunningError(BridgeError):
    """Raised when the bridge server is not running or configuration is missing."""
    pass


class BridgeAuthenticationError(BridgeError):
    """Raised on HTTP 401: Invalid or missing authentication token."""
    pass


class BridgeBadRequestError(BridgeError):
    """Raised on HTTP 400: Malformed request or invalid parameters."""
    pass


class BridgeConflictError(BridgeError):
    """Raised on HTTP 409: Resource conflict, e.g. typing operation already in progress."""
    pass


class BridgePayloadTooLargeError(BridgeError):
    """Raised on HTTP 413: Request body or file exceeds permitted size."""
    pass


class BridgeServerError(BridgeError):
    """Raised on HTTP 500: Server-side internal error in VS Code bridge."""
    pass


class BridgeConnectionError(BridgeError):
    """Raised on network failure, connection refused, or unexpected disconnect."""
    pass


# =====================================================================
# BACKGROUND CLIENT
# =====================================================================

class BackgroundClient:
    """
    Python client for communicating with the GitType VS Code Extension Background Engine.

    Features:
    - Auto-discovery of active bridge configuration (.gittype-bridge.json).
    - Full authentication using constant-time verified tokens.
    - Localhost-only (127.0.0.1) communication.
    - Specific handling for HTTP 400, 401, 404, 409, 413, 429, and 500 responses.
    - Safe retries on transient connection issues.
    - Never prints authentication tokens to stdout or logs.
    - Supports both single-file and multi-file background typing.
    - Supports incremental (human-like) and fast typing modes.
    - Pause, resume, stop, and status monitoring.
    """

    def __init__(self, project_root: Optional[Union[str, Path]] = None):
        self.root = Path(
            project_root or Path(__file__).resolve().parent.parent
        ).resolve()

        self.config_file = self.root / ".gittype-bridge.json"
        self._cached_config: Optional[Dict[str, Any]] = None

    def __repr__(self) -> str:
        cached_port = self._cached_config.get("port") if self._cached_config else "unknown"
        return f"<BackgroundClient root='{self.root}' active_port={cached_port}>"

    # =================================================================
    # CONFIGURATION & DISCOVERY
    # =================================================================

    def get_config(self, reload: bool = False, max_retries: int = 3, retry_delay: float = 0.5) -> Dict[str, Any]:
        """
        Discover and load bridge configuration (.gittype-bridge.json).
        Retries up to max_retries if the configuration file is being written.
        """
        if self._cached_config and not reload:
            return self._cached_config

        for attempt in range(max_retries):
            if self.config_file.exists():
                try:
                    raw_data = self.config_file.read_text(encoding="utf-8").strip()
                    if raw_data:
                        config = json.loads(raw_data)
                        if "host" in config and "port" in config and "token" in config:
                            self._cached_config = config
                            return config
                except (json.JSONDecodeError, OSError):
                    pass

            if attempt < max_retries - 1:
                time.sleep(retry_delay)

        raise BridgeNotRunningError(
            f"GitType bridge configuration not found or invalid at '{self.config_file}'. "
            "Please ensure the VS Code Extension Development Host is running."
        )

    def invalidate_config(self):
        """Clear cached configuration to force rediscovery on next request."""
        self._cached_config = None

    # =================================================================
    # LOW-LEVEL HTTP REQUEST DISPATCHER
    # =================================================================

    def request(
        self,
        endpoint: str,
        data: Optional[Dict[str, Any]] = None,
        method: Optional[str] = None,
        timeout: float = 300.0,
        max_retries: int = 2
    ) -> Dict[str, Any]:
        """
        Send an authenticated HTTP request to the VS Code bridge server.
        """
        endpoint = endpoint.lstrip("/")

        for attempt in range(max_retries + 1):
            try:
                config = self.get_config(reload=(attempt > 0))
                host = config.get("host", "127.0.0.1")
                port = config.get("port")
                token = config.get("token")

                url = f"http://{host}:{port}/{endpoint}"

                headers = {
                    "X-GitType-Token": token,
                    "Accept": "application/json"
                }

                body_bytes = None
                if data is not None:
                    body_bytes = json.dumps(data).encode("utf-8")
                    headers["Content-Type"] = "application/json; charset=utf-8"

                req_method = method or ("POST" if data is not None else "GET")
                req = urllib.request.Request(
                    url,
                    data=body_bytes,
                    headers=headers,
                    method=req_method
                )

                with urllib.request.urlopen(req, timeout=timeout) as response:
                    res_body = response.read().decode("utf-8", errors="replace")
                    if not res_body.strip():
                        return {"success": True}
                    return json.loads(res_body)

            except urllib.error.HTTPError as err:
                raw_err = err.read().decode("utf-8", errors="replace")
                parsed_msg = raw_err
                try:
                    err_json = json.loads(raw_err)
                    parsed_msg = err_json.get("error", raw_err)
                except Exception:
                    pass

                code = err.code
                if code == 400:
                    raise BridgeBadRequestError(f"HTTP 400 Bad Request: {parsed_msg}") from err
                elif code == 401:
                    raise BridgeAuthenticationError(f"HTTP 401 Unauthorized: {parsed_msg}") from err
                elif code == 404:
                    raise BridgeError(f"HTTP 404 Not Found: {parsed_msg}") from err
                elif code == 409:
                    raise BridgeConflictError(f"HTTP 409 Conflict: {parsed_msg}") from err
                elif code == 413:
                    raise BridgePayloadTooLargeError(f"HTTP 413 Payload Too Large: {parsed_msg}") from err
                elif code == 500:
                    raise BridgeServerError(f"HTTP 500 Server Error: {parsed_msg}") from err
                else:
                    raise BridgeError(f"HTTP {code}: {parsed_msg}") from err

            except (urllib.error.URLError, ConnectionResetError, ConnectionRefusedError, OSError) as err:
                self.invalidate_config()
                if attempt < max_retries:
                    time.sleep(0.5)
                    continue

                raise BridgeConnectionError(
                    f"Cannot communicate with GitType bridge at '{endpoint}': {err}. "
                    "Make sure VS Code Extension Host is active."
                ) from err

    # =================================================================
    # PUBLIC API ENDPOINTS
    # =================================================================

    def health(self, timeout: float = 5.0) -> Dict[str, Any]:
        """
        Check health and status of the bridge server (unauthenticated GET /health).
        """
        try:
            config = self.get_config()
            url = f"http://{config['host']}:{config['port']}/health"

            req = urllib.request.Request(url, method="GET")
            with urllib.request.urlopen(req, timeout=timeout) as response:
                return json.loads(response.read().decode("utf-8", errors="replace"))

        except Exception as err:
            self.invalidate_config()
            raise BridgeConnectionError(
                f"Health check failed for GitType bridge: {err}"
            ) from err

    def is_connected(self) -> tuple:
        """
        Check if bridge is reachable and return (is_connected: bool, message: str, details: dict).
        """
        try:
            h = self.health(timeout=2.0)
            if h.get("success"):
                cfg = self._cached_config or {}
                port = cfg.get("port", "unknown")
                return True, f"Connected (Port {port})", h
            return False, "Bridge returned failure response", h
        except BridgeNotRunningError:
            return False, "Bridge not running (.gittype-bridge.json missing)", {}
        except Exception as e:
            return False, f"Disconnected ({type(e).__name__})", {}

    def status(self) -> Dict[str, Any]:
        """
        Retrieve detailed engine status, current job progress, and history.
        """
        return self.request("status", method="GET", timeout=10.0)

    def pause(self) -> Dict[str, Any]:
        """Pause active background typing operation."""
        return self.request("pause", data={})

    def resume(self) -> Dict[str, Any]:
        """Resume paused background typing operation."""
        return self.request("resume", data={})

    def stop(self) -> Dict[str, Any]:
        """Stop active background typing immediately."""
        return self.request("stop", data={})

    def cancel(self) -> Dict[str, Any]:
        """Cancel current job and clear all queued operations."""
        return self.request("cancel", data={})

    # =================================================================
    # TYPING OPERATIONS (SINGLE & MULTI-FILE)
    # =================================================================

    def type_text(
        self,
        destination: Union[str, Path],
        content: str,
        delay: int = 30,
        mode: str = "incremental",
        overwrite: bool = False,
        timeout: float = 600.0
    ) -> Dict[str, Any]:
        """
        Perform background typing of text content into a single destination file.

        :param destination: Relative or absolute path inside the project workspace.
        :param content: String text to insert.
        :param delay: Inter-chunk typing delay in milliseconds.
        :param mode: 'incremental' (human-like) or 'fast' (direct document edit).
        :param overwrite: If True, replace content of an existing file.
        :param timeout: Request timeout in seconds.
        :return: Execution summary dictionary.
        """
        dest_path = Path(destination).resolve()

        if not dest_path.is_relative_to(self.root):
            raise ValueError(
                f"Destination path '{dest_path}' must reside inside the GitType workspace '{self.root}'."
            )

        payload = {
            "path": str(dest_path),
            "content": content,
            "delay": delay,
            "mode": mode,
            "overwrite": overwrite
        }

        return self.request("type", data=payload, timeout=timeout)

    def type_file(
        self,
        source: Union[str, Path],
        destination: Union[str, Path],
        delay: int = 30,
        mode: str = "incremental",
        overwrite: bool = False,
        timeout: float = 600.0
    ) -> Dict[str, Any]:
        """
        Read content from a source file and type it into the destination file via background engine.
        """
        src_path = Path(source).resolve()
        if not src_path.is_file():
            raise FileNotFoundError(f"Source file not found: {src_path}")

        content = src_path.read_text(encoding="utf-8")
        return self.type_text(
            destination=destination,
            content=content,
            delay=delay,
            mode=mode,
            overwrite=overwrite,
            timeout=timeout
        )

    def type_files(
        self,
        files: List[Union[Dict[str, Any], tuple]],
        delay: int = 30,
        mode: str = "incremental",
        overwrite: bool = False,
        job_id: Optional[str] = None,
        stop_on_error: bool = True,
        timeout: float = 1200.0
    ) -> Dict[str, Any]:
        """
        Perform background typing on multiple files within a single managed job.

        :param files: List of dicts [{'path': ..., 'content': ..., 'overwrite': bool}]
                      or tuples (path, content).
        :param delay: Delay in ms.
        :param mode: 'incremental' or 'fast'.
        :param overwrite: Default overwrite setting if not specified per file.
        :param job_id: Optional custom job identifier.
        :param stop_on_error: Stop job on first file failure if True.
        :param timeout: Total timeout in seconds.
        :return: Job summary dict.
        """
        formatted_files = []
        for item in files:
            if isinstance(item, tuple):
                p, c = item
                formatted_files.append({
                    "path": str(Path(p).resolve()),
                    "content": c,
                    "overwrite": overwrite
                })
            elif isinstance(item, dict):
                p = item.get("path") or item.get("destination") or item.get("file")
                c = item.get("content", "")
                ow = item.get("overwrite", overwrite)
                formatted_files.append({
                    "path": str(Path(p).resolve()),
                    "content": c,
                    "overwrite": ow
                })
            else:
                raise ValueError(f"Invalid file item specification: {item}")

        payload = {
            "job_id": job_id,
            "files": formatted_files,
            "delay": delay,
            "mode": mode,
            "overwrite": overwrite,
            "stopOnError": stop_on_error
        }

        return self.request("type", data=payload, timeout=timeout)

    # =================================================================
    # ASYNC JOB MONITORING HELPER
    # =================================================================

    def wait_for_completion(
        self,
        timeout: float = 300.0,
        poll_interval: float = 0.5,
        on_progress: Optional[Callable[[Dict[str, Any]], None]] = None
    ) -> Dict[str, Any]:
        """
        Poll bridge status until typing finishes or timeout expires.
        """
        start_time = time.time()
        while time.time() - start_time < timeout:
            current_status = self.status()
            if on_progress:
                on_progress(current_status)

            if not current_status.get("typing", False):
                return current_status

            time.sleep(poll_interval)

        raise TimeoutError(f"Background typing did not finish within {timeout} seconds.")
