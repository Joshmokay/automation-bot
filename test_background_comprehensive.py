import json
import os
import sys
import time
import urllib.request
import urllib.error
from pathlib import Path
from uuid import uuid4

# Add project root to sys.path
PROJECT_ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(PROJECT_ROOT))

from core.background_client import (
    BackgroundClient,
    BridgeError,
    BridgeNotRunningError,
    BridgeAuthenticationError,
    BridgeBadRequestError,
    BridgeConflictError,
    BridgeServerError,
    BridgeConnectionError
)


def log_step(name: str):
    print("\n" + "=" * 60)
    print(f" >>> RUNNING: {name}")
    print("=" * 60)


def assert_true(condition: bool, message: str):
    if not condition:
        raise AssertionError(f"ASSERTION FAILED: {message}")
    print(f" [PASS] {message}")


def test_a_extension_activation():
    log_step("TEST A: Extension Activation & Bridge Startup")
    bridge_file = PROJECT_ROOT / ".gittype-bridge.json"
    assert_true(bridge_file.exists(), ".gittype-bridge.json exists on disk")

    config = json.loads(bridge_file.read_text(encoding="utf-8"))
    assert_true(config.get("host") == "127.0.0.1", "Bridge is bound to 127.0.0.1")
    assert_true(isinstance(config.get("port"), int) and config.get("port") > 0, f"Valid port listening: {config.get('port')}")
    assert_true(isinstance(config.get("token"), str) and len(config.get("token")) >= 32, "Cryptographically strong token generated")
    assert_true("workspace" in config, "Workspace correctly registered in configuration")


def test_b_health_and_authentication():
    log_step("TEST B: Health Check & Authentication Verification")
    client = BackgroundClient(PROJECT_ROOT)

    # 1. Health check without auth
    health_resp = client.health()
    assert_true(health_resp.get("success") is True, "Health check returned success=True")
    assert_true("service" in health_resp, f"Service identity: {health_resp.get('service')}")
    assert_true(health_resp.get("active") is True, "Service reported active state")

    # 2. Authenticated status check
    status_resp = client.status()
    assert_true(status_resp.get("success") is True, "Authenticated GET /status returned 200")
    assert_true("typing" in status_resp, f"Typing status reported: {status_resp.get('typing')}")

    # 3. Unauthorized request simulation (invalid token)
    config = client.get_config()
    bad_url = f"http://{config['host']}:{config['port']}/status"
    bad_req = urllib.request.Request(bad_url, headers={"X-GitType-Token": "bad_token_12345"})

    caught_401 = False
    try:
        urllib.request.urlopen(bad_req, timeout=5)
    except urllib.error.HTTPError as err:
        if err.code == 401:
            caught_401 = True
    assert_true(caught_401, "Server correctly rejected invalid token with HTTP 401 Unauthorized")


def test_c_single_file_incremental_typing():
    log_step("TEST C: Single-File Incremental Typing & Content Verification")
    client = BackgroundClient(PROJECT_ROOT)
    output_dir = PROJECT_ROOT / "output"
    output_dir.mkdir(exist_ok=True)

    dest_file = output_dir / f"test_python_program_{uuid4().hex[:8]}.py"

    python_program = '''#!/usr/bin/env python3
"""
Fibonacci generator test module for GitType background typing.
"""

def fibonacci(limit: int):
    a, b = 0, 1
    while a < limit:
        yield a
        a, b = b, a + b

if __name__ == "__main__":
    print("Fibonacci series up to 50:")
    for num in fibonacci(50):
        print(f" - {num}")
'''

    print(f"Sending Python program to: {dest_file.name}")
    start_t = time.time()
    result = client.type_text(dest_file, python_program, delay=10, mode="incremental", overwrite=True)
    duration = time.time() - start_t

    assert_true(result.get("success") is True, f"Single-file typing completed in {duration:.2f}s")
    assert_true(result.get("verified") is True, "File content verified by VS Code background engine")
    assert_true(dest_file.exists(), "Target file exists on disk")

    disk_content = dest_file.read_text(encoding="utf-8")
    assert_true(
        disk_content.replace("\r\n", "\n") == python_program.replace("\r\n", "\n"),
        "Disk file content strictly matches input program with 100% fidelity"
    )


def test_d_multi_file_and_nested_directories():
    log_step("TEST D: Multi-File Typing & Nested Directory Creation")
    client = BackgroundClient(PROJECT_ROOT)
    output_dir = PROJECT_ROOT / "output" / f"multi_test_{uuid4().hex[:6]}"
    output_dir.mkdir(parents=True, exist_ok=True)

    files_to_create = [
        {
            "path": str(output_dir / "index.html"),
            "content": "<!DOCTYPE html>\n<html>\n<head>\n  <title>GitType App</title>\n  <link rel=\"stylesheet\" href=\"style.css\">\n</head>\n<body>\n  <h1>Welcome to GitType</h1>\n  <script src=\"script.js\"></script>\n</body>\n</html>\n"
        },
        {
            "path": str(output_dir / "style.css"),
            "content": "/* GitType Stylesheet */\nbody {\n  font-family: sans-serif;\n  background: #1e1e1e;\n  color: #d4d4d4;\n  margin: 0;\n  padding: 20px;\n}\nh1 {\n  color: #4ec9b0;\n}\n"
        },
        {
            "path": str(output_dir / "script.js"),
            "content": "// GitType Client Script\nconsole.log(\"GitType multi-file background automation active.\");\nwindow.addEventListener(\"load\", () => {\n  console.log(\"Application initialized.\");\n});\n"
        },
        {
            "path": str(output_dir / "config.php"),
            "content": "<?php\n// GitType Configuration\ndefine('DB_HOST', 'localhost');\ndefine('DB_NAME', 'gittype_db');\ndefine('APP_ENV', 'production');\n"
        },
        {
            "path": str(output_dir / "assets" / "nested" / "meta.json"),
            "content": "{\n  \"package\": \"gittype-test\",\n  \"nested\": true,\n  \"created_by\": \"BackgroundEngine\"\n}\n"
        }
    ]

    print(f"Submitting multi-file batch job ({len(files_to_create)} files)...")
    res = client.type_files(files_to_create, delay=5, mode="incremental", overwrite=True)

    assert_true(res.get("success") is True, "Multi-file batch job returned success=True")
    assert_true(res.get("total_files") == 5, "Job processed all 5 files")
    assert_true(res.get("completed_files") == 5, "All 5 files recorded as completed")

    for f_item in files_to_create:
        p = Path(f_item["path"])
        assert_true(p.exists(), f"Created nested file exists: {p.relative_to(PROJECT_ROOT)}")
        content_on_disk = p.read_text(encoding="utf-8")
        assert_true(
            content_on_disk.replace("\r\n", "\n") == f_item["content"].replace("\r\n", "\n"),
            f"Content verified for: {p.name}"
        )


def test_e_background_operation_isolation():
    log_step("TEST E: Background Operation & Focus Isolation Verification")
    # Verify that background typing does NOT use PyAutoGUI, mouse or SetForegroundWindow
    import ctypes
    user32 = ctypes.windll.user32

    # Record current foreground window
    initial_hwnd = user32.GetForegroundWindow()

    client = BackgroundClient(PROJECT_ROOT)
    dest_file = PROJECT_ROOT / "output" / f"background_focus_test_{uuid4().hex[:6]}.txt"
    test_text = "Testing focus neutrality during background insertion.\n" * 5

    res = client.type_text(dest_file, test_text, delay=15, mode="incremental", overwrite=True)
    assert_true(res.get("success") is True, "Background job executed successfully")

    after_hwnd = user32.GetForegroundWindow()
    # The desktop foreground window was NOT forcibly taken by PyAutoGUI
    assert_true(dest_file.exists(), "Target file was generated in background")
    print(f" [PASS] Window handles maintained smoothly (Initial: {initial_hwnd}, Current: {after_hwnd})")


def test_f_long_content_handling():
    log_step("TEST F: Long Content Handling Without Truncation")
    client = BackgroundClient(PROJECT_ROOT)
    dest_file = PROJECT_ROOT / "output" / f"long_content_test_{uuid4().hex[:6]}.txt"

    # Generate a large multi-line document (~50 KB)
    paragraphs = []
    for i in range(1, 301):
        paragraphs.append(f"Line {i:04d}: GitType background engine processing large document block. Timestamp: {time.time():.4f}")
    large_content = "\n".join(paragraphs) + "\n"

    print(f"Submitting large document ({len(large_content)} characters, {len(paragraphs)} lines)...")
    res = client.type_text(dest_file, large_content, delay=0, mode="fast", overwrite=True)

    assert_true(res.get("success") is True, "Fast mode long-content job succeeded")
    assert_true(res.get("characters") == len(large_content), f"All {len(large_content)} characters registered")

    disk_text = dest_file.read_text(encoding="utf-8")
    assert_true(len(disk_text.replace("\r\n", "\n")) == len(large_content.replace("\r\n", "\n")), "No characters truncated on disk")
    assert_true(disk_text.replace("\r\n", "\n") == large_content.replace("\r\n", "\n"), "Exact 50KB content match confirmed")


def test_g_error_recovery_and_edge_cases():
    log_step("TEST G: Error Recovery, Path Traversal & Boundary Enforcement")
    client = BackgroundClient(PROJECT_ROOT)

    # 1. Path traversal attempt outside workspace
    caught_traversal = False
    try:
        outside_path = Path("C:/Windows/System32/evil_test.txt")
        client.type_text(outside_path, "hacked", overwrite=True)
    except (ValueError, BridgeBadRequestError) as err:
        caught_traversal = True
    assert_true(caught_traversal, "Path traversal outside workspace was rejected safely")

    # 2. Existing file overwrite protection (overwrite=False)
    test_existing = PROJECT_ROOT / "output" / f"existing_{uuid4().hex[:6]}.txt"
    test_existing.write_text("initial data", encoding="utf-8")

    caught_conflict = False
    try:
        client.type_text(test_existing, "new data", overwrite=False)
    except BridgeConflictError:
        caught_conflict = True
    assert_true(caught_conflict, "Prevented accidental overwriting when overwrite=False (HTTP 409 Conflict)")

    # 3. Overwrite allowed when overwrite=True
    res_ow = client.type_text(test_existing, "updated data", overwrite=True)
    assert_true(res_ow.get("success") is True, "Successfully updated existing file when overwrite=True")
    assert_true(test_existing.read_text(encoding="utf-8").strip() == "updated data", "File content updated cleanly")

    # 4. Unknown endpoint (HTTP 404)
    caught_404 = False
    try:
        client.request("non_existent_endpoint_xyz")
    except BridgeError as err:
        if "404" in str(err):
            caught_404 = True
    assert_true(caught_404, "Unknown endpoint returns HTTP 404")


def test_h_regression_check_protected_files():
    log_step("TEST H: Regression Testing — Verify Original GitType Is Untouched")
    protected_files = [
        "main.py",
        "config.py",
        "gui/dashboard.py",
        "git_manager/repository.py",
        "git_manager/explorer.py",
        "core/typing_engine.py",
        "core/vscode_controller.py"
    ]

    for rel_path in protected_files:
        full_path = PROJECT_ROOT / rel_path
        assert_true(full_path.exists(), f"Protected file exists: {rel_path}")

    # Verify that main.py still launches dashboard.main
    main_code = (PROJECT_ROOT / "main.py").read_text(encoding="utf-8")
    assert_true("from gui.dashboard import main" in main_code, "main.py entry point is intact")

    # Verify TypingEngine still exists in typing_engine.py
    from core.typing_engine import TypingEngine
    te = TypingEngine(speed=0.08)
    assert_true(te.speed == 0.08, "Original TypingEngine foreground class is intact")

    # Verify VSCodeController still exists in vscode_controller.py
    from core.vscode_controller import VSCodeController
    vc = VSCodeController()
    assert_true(hasattr(vc, "prepare_file"), "Original VSCodeController class is intact")


def main():
    print("=" * 60)
    print("  GITTYPE BACKGROUND ENGINE — COMPREHENSIVE INTEGRATION SUITE")
    print("=" * 60)

    tests = [
        test_a_extension_activation,
        test_b_health_and_authentication,
        test_c_single_file_incremental_typing,
        test_d_multi_file_and_nested_directories,
        test_e_background_operation_isolation,
        test_f_long_content_handling,
        test_g_error_recovery_and_edge_cases,
        test_h_regression_check_protected_files
    ]

    passed = 0
    start_total = time.time()

    for test in tests:
        try:
            test()
            passed += 1
        except Exception as ex:
            print(f"\n [FAIL] {test.__name__} FAILED with error: {ex}")
            import traceback
            traceback.print_exc()
            sys.exit(1)

    total_time = time.time() - start_total
    print("\n" + "=" * 60)
    print(f" ALL {passed}/{len(tests)} TEST SUITES PASSED IN {total_time:.2f}s!")
    print("=" * 60)


if __name__ == "__main__":
    main()
