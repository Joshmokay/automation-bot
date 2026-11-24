import os
import sys
import time
import shutil
from pathlib import Path
from uuid import uuid4

# Set up project path
ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))

from PySide6.QtWidgets import QApplication
from PySide6.QtCore import Qt

# Ensure QApplication instance exists
app = QApplication.instance()
if not app:
    app = QApplication(sys.argv)

from gui.dashboard import GitTypeDashboard, BackgroundAutomationWorker, AutomationWorker
from git_manager.repository import RepositoryManager
from git_manager.explorer import RepositoryExplorer
from core.background_client import BackgroundClient


def log_test(name: str):
    print("\n" + "=" * 65)
    print(f" >>> RUNNING INTEGRATION TEST: {name}")
    print("=" * 65)


def assert_condition(cond: bool, msg: str):
    if not cond:
        raise AssertionError(f"TEST FAILED: {msg}")
    print(f" [PASS] {msg}")


def wait_worker(worker, timeout_sec=15.0):
    """Wait for QThread worker while dispatching Qt events."""
    start = time.time()
    while worker.isRunning() and (time.time() - start < timeout_sec):
        QApplication.processEvents()
        time.sleep(0.02)
    QApplication.processEvents()


def create_mock_git_repo(repo_dir: Path):
    """Create a clean git repository with nested structures and sample source files."""
    if repo_dir.exists():
        shutil.rmtree(repo_dir, ignore_errors=True)
    repo_dir.mkdir(parents=True, exist_ok=True)

    from git import Repo
    repo = Repo.init(repo_dir)

    # 1. Root files
    (repo_dir / "README.md").write_text("# Mock Project\nAutomated GitType test project.\n", encoding="utf-8")
    (repo_dir / "index.html").write_text("<!DOCTYPE html>\n<html><body><h1>GitType</h1></body></html>\n", encoding="utf-8")

    # 2. Nested files
    src_dir = repo_dir / "src" / "components"
    src_dir.mkdir(parents=True, exist_ok=True)
    (src_dir / "app.js").write_text("console.log('App component loaded');\nexport default function App() {}\n", encoding="utf-8")
    (src_dir / "theme.css").write_text("body { background: #222; color: #fff; }\n", encoding="utf-8")

    assets_dir = repo_dir / "assets" / "config"
    assets_dir.mkdir(parents=True, exist_ok=True)
    (assets_dir / "settings.json").write_text("{\n  \"env\": \"test\",\n  \"active\": true\n}\n", encoding="utf-8")

    # 3. Add excluded directories to test exclusion filtering
    node_dir = repo_dir / "node_modules" / "dummy"
    node_dir.mkdir(parents=True, exist_ok=True)
    (node_dir / "package.json").write_text("{}", encoding="utf-8")

    venv_dir = repo_dir / "venv" / "bin"
    venv_dir.mkdir(parents=True, exist_ok=True)
    (venv_dir / "activate").write_text("# dummy activate", encoding="utf-8")

    # Git add and commit
    repo.index.add(["README.md", "index.html", "src/components/app.js", "src/components/theme.css", "assets/config/settings.json"])
    repo.index.commit("Initial commit for integration testing")
    print(f"Initialized mock Git repository at: {repo_dir}")
    return repo_dir


# =====================================================================
# TEST CASES
# =====================================================================

def test_1_bridge_connection_from_dashboard():
    log_test("1. Bridge Connection from Dashboard")
    dashboard = GitTypeDashboard()

    # Verify initial bridge check
    connected, msg, details = dashboard.background_client.is_connected()
    assert_condition(connected is True, f"Dashboard BackgroundClient reports connected: {msg}")

    # Verify UI indicator updated
    assert_condition("Connected" in dashboard.bridge_status_indicator.text(), "Dashboard indicator shows Connected")
    assert_condition("Port" in dashboard.bridge_status_indicator.text(), "Dashboard indicator specifies port")
    print("Bridge connection from dashboard verified successfully.")


def test_2_repository_scanning_and_exclusion():
    log_test("2. Repository Scanning & Directory Exclusion")
    test_repo = ROOT / "repositories" / "test_integration_repo"
    create_mock_git_repo(test_repo)

    explorer = RepositoryExplorer(test_repo)
    discovered_files = explorer.get_files()

    rel_files = [str(Path(f).relative_to(test_repo)).replace("\\", "/") for f in discovered_files]
    print(f"Discovered {len(rel_files)} files: {rel_files}")

    assert_condition("README.md" in rel_files, "Discovered README.md")
    assert_condition("index.html" in rel_files, "Discovered index.html")
    assert_condition("src/components/app.js" in rel_files, "Discovered nested app.js")
    assert_condition("src/components/theme.css" in rel_files, "Discovered nested theme.css")
    assert_condition("assets/config/settings.json" in rel_files, "Discovered assets/config/settings.json")

    # Exclusions
    for f in rel_files:
        assert_condition(".git" not in f, f"Exclusion verified (.git not in {f})")
        assert_condition("node_modules" not in f, f"Exclusion verified (node_modules not in {f})")
        assert_condition("venv" not in f, f"Exclusion verified (venv not in {f})")


def test_3_single_file_automation():
    log_test("3. Single-File Background Automation via Worker")
    test_repo = ROOT / "repositories" / "test_integration_repo"
    output_dir = ROOT / "output" / f"single_test_{uuid4().hex[:6]}"

    target_file = [str(test_repo / "README.md")]

    worker = BackgroundAutomationWorker(
        source_root=test_repo,
        files=target_file,
        output_root=output_dir,
        speed=0.01,
        mode="incremental",
        overwrite=True
    )

    started_events = []
    completed_events = []

    worker.message.connect(lambda m: print("WORKER MSG:", m))
    worker.failed.connect(lambda e: print("WORKER FAILED:", e))
    worker.file_started.connect(lambda p, i, t: started_events.append(p))
    worker.file_completed.connect(lambda p: completed_events.append(p))

    worker.start()
    wait_worker(worker, 15.0)

    assert_condition(len(started_events) == 1, "file_started signal emitted")
    assert_condition(len(completed_events) == 1, "file_completed signal emitted")

    dest_file = output_dir / "README.md"
    assert_condition(dest_file.exists(), f"Destination file exists: {dest_file}")

    expected = (test_repo / "README.md").read_text(encoding="utf-8").replace("\r\n", "\n")
    actual = dest_file.read_text(encoding="utf-8").replace("\r\n", "\n")
    assert_condition(expected == actual, "Content verified with 100% exact match")


def test_4_multi_file_and_nested_folder_automation():
    log_test("4. Multi-File Repository Automation & Nested Hierarchy Preservation")
    test_repo = ROOT / "repositories" / "test_integration_repo"
    output_dir = ROOT / "output" / f"repo_auto_{uuid4().hex[:6]}"

    explorer = RepositoryExplorer(test_repo)
    all_files = explorer.get_files()

    worker = BackgroundAutomationWorker(
        source_root=test_repo,
        files=all_files,
        output_root=output_dir,
        speed=0.01,
        mode="fast", # fast mode for rapid verification
        overwrite=True
    )

    completed_signals = []
    summary_holder = []

    worker.file_completed.connect(lambda p: completed_signals.append(p))
    worker.completed.connect(lambda s: summary_holder.append(s))

    worker.start()
    wait_worker(worker, 30.0)

    assert_condition(len(summary_holder) == 1, "Summary completion signal received")
    summary = summary_holder[0]
    print("TEST 4 SUMMARY:", summary)
    assert_condition(summary["completed"] == len(all_files), f"All {len(all_files)} files completed successfully")
    assert_condition(summary["failed"] == 0, "Zero failures reported")

    # Verify nested files exist
    assert_condition((output_dir / "src" / "components" / "app.js").exists(), "Preserved nested path: src/components/app.js")
    assert_condition((output_dir / "src" / "components" / "theme.css").exists(), "Preserved nested path: src/components/theme.css")
    assert_condition((output_dir / "assets" / "config" / "settings.json").exists(), "Preserved nested path: assets/config/settings.json")


def test_5_pause_resume_and_stop_in_worker():
    log_test("5. Pause, Resume, and Stop in BackgroundAutomationWorker")
    test_repo = ROOT / "repositories" / "test_integration_repo"
    output_dir = ROOT / "output" / f"ctrl_test_{uuid4().hex[:6]}"

    # Create a large source file to give time for pause/resume
    large_file = test_repo / "large_control_test.txt"
    large_file.write_text("GitType concurrency and interactive control testing line.\n" * 80, encoding="utf-8")

    worker = BackgroundAutomationWorker(
        source_root=test_repo,
        files=[str(large_file)],
        output_root=output_dir,
        speed=0.05,
        mode="incremental",
        overwrite=True
    )

    worker.start()
    time.sleep(0.4)

    # Pause
    print("Triggering pause_typing()...")
    worker.pause_typing()
    time.sleep(0.3)
    assert_condition(worker.is_paused is True, "Worker is_paused flag is True")

    client = BackgroundClient()
    st = client.status()
    assert_condition(st.get("paused") is True, "VS Code engine status reports paused=True")

    # Resume
    print("Triggering resume_typing()...")
    worker.resume_typing()
    time.sleep(0.3)
    assert_condition(worker.is_paused is False, "Worker is_paused flag is False")
    st = client.status()
    assert_condition(st.get("paused") is False, "VS Code engine status reports paused=False")

    # Stop
    print("Triggering stop_typing()...")
    worker.stop_typing()
    wait_worker(worker, 10.0)

    assert_condition(not worker.isRunning(), "Worker stopped cleanly without crashing")


def test_6_destination_conflict_handling():
    log_test("6. Destination Conflict Handling (Overwrite Protection)")
    test_repo = ROOT / "repositories" / "test_integration_repo"
    output_dir = ROOT / "output" / f"conflict_test_{uuid4().hex[:6]}"
    output_dir.mkdir(parents=True, exist_ok=True)

    # Create pre-existing file with content
    conflict_file = output_dir / "index.html"
    conflict_file.write_text("PRE-EXISTING CONTENT", encoding="utf-8")

    # 1. Run with overwrite=False
    worker = BackgroundAutomationWorker(
        source_root=test_repo,
        files=[str(test_repo / "index.html")],
        output_root=output_dir,
        speed=0.01,
        mode="fast",
        overwrite=False
    )

    failed_signals = []
    worker.file_failed.connect(lambda p, err: failed_signals.append((p, err)))
    worker.start()
    wait_worker(worker, 10.0)

    assert_condition(len(failed_signals) == 1, "Conflict reported when overwrite=False")
    assert_condition("overwrite" in failed_signals[0][1].lower(), "Error message indicates overwrite required")
    assert_condition(conflict_file.read_text(encoding="utf-8") == "PRE-EXISTING CONTENT", "Pre-existing content protected")

    # 2. Run with overwrite=True
    worker_ow = BackgroundAutomationWorker(
        source_root=test_repo,
        files=[str(test_repo / "index.html")],
        output_root=output_dir,
        speed=0.01,
        mode="fast",
        overwrite=True
    )
    worker_ow.start()
    wait_worker(worker_ow, 10.0)

    assert_condition(worker_ow.completed_count == 1, "File updated successfully when overwrite=True")
    new_content = conflict_file.read_text(encoding="utf-8")
    assert_condition("GitType" in new_content, "Content replaced cleanly with source text")


def test_7_large_file_and_unicode_handling():
    log_test("7. Large-File & Unicode Encoding Handling")
    test_repo = ROOT / "repositories" / "test_integration_repo"
    output_dir = ROOT / "output" / f"large_unicode_{uuid4().hex[:6]}"

    # File with Unicode symbols, Greek, Japanese, and emojis
    unicode_file = test_repo / "unicode_test.txt"
    unicode_content = (
        "English: Hello GitType!\n"
        "Greek: Γειά σου κόσμε!\n"
        "Japanese: こんにちは世界！\n"
        "Emojis: 🚀 ✨ 💻 ⚡ 🛡️\n"
        "Indented code:\n    for x in range(10):\n        print(x)\n"
    ) * 10
    unicode_file.write_text(unicode_content, encoding="utf-8")

    worker = BackgroundAutomationWorker(
        source_root=test_repo,
        files=[str(unicode_file)],
        output_root=output_dir,
        speed=0.01,
        mode="incremental",
        overwrite=True
    )
    worker.start()
    wait_worker(worker, 15.0)

    assert_condition(worker.completed_count == 1, "Unicode file completed")
    dest_path = output_dir / "unicode_test.txt"
    actual_content = dest_path.read_text(encoding="utf-8")
    assert_condition(
        unicode_content.replace("\r\n", "\n") == actual_content.replace("\r\n", "\n"),
        "Unicode characters and surrogate pairs verified 100% intact"
    )


def test_8_human_typing_mode_intact():
    log_test("8. Regression Check: Human Typing Mode Remains Intact")
    # Verify AutomationWorker class can be instantiated with its original arguments
    test_repo = ROOT / "repositories" / "test_integration_repo"
    output_dir = ROOT / "output" / f"human_test_{uuid4().hex[:6]}"

    human_worker = AutomationWorker(
        source_root=test_repo,
        files=[str(test_repo / "README.md")],
        output_root=output_dir,
        speed=0.08
    )

    assert_condition(hasattr(human_worker, "controller"), "Human worker has VSCodeController")
    assert_condition(hasattr(human_worker, "bot"), "Human worker has TypingEngine")
    assert_condition(hasattr(human_worker, "pause_typing"), "Human worker has pause_typing")
    assert_condition(hasattr(human_worker, "resume_typing"), "Human worker has resume_typing")
    assert_condition(hasattr(human_worker, "stop_typing"), "Human worker has stop_typing")
    print("Human Typing Mode structure confirmed 100% intact.")


# =====================================================================
# MAIN TEST RUNNER
# =====================================================================

def main():
    print("=" * 65)
    print(" GITTYPE — COMPLETE APPLICATION INTEGRATION VERIFICATION")
    print("=" * 65)

    tests = [
        test_1_bridge_connection_from_dashboard,
        test_2_repository_scanning_and_exclusion,
        test_3_single_file_automation,
        test_4_multi_file_and_nested_folder_automation,
        test_5_pause_resume_and_stop_in_worker,
        test_6_destination_conflict_handling,
        test_7_large_file_and_unicode_handling,
        test_8_human_typing_mode_intact
    ]

    passed = 0
    start_time = time.time()

    for t in tests:
        try:
            t()
            passed += 1
        except Exception as e:
            print(f"\n [FAIL] {t.__name__} FAILED with error: {e}")
            import traceback
            traceback.print_exc()
            sys.exit(1)

    elapsed = time.time() - start_time
    print("\n" + "=" * 65)
    print(f" ALL {passed}/{len(tests)} APPLICATION INTEGRATION TESTS PASSED in {elapsed:.2f}s!")
    print("=" * 65)
    sys.exit(0)


if __name__ == "__main__":
    main()
