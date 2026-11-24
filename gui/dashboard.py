import sys
import time
from pathlib import Path

import pyautogui

from PySide6.QtCore import Qt, QThread, Signal, QTimer
from PySide6.QtWidgets import (
    QApplication,
    QMainWindow,
    QWidget,
    QVBoxLayout,
    QHBoxLayout,
    QLineEdit,
    QPushButton,
    QLabel,
    QTreeWidget,
    QTreeWidgetItem,
    QDoubleSpinBox,
    QFileDialog,
    QTextEdit,
    QProgressBar,
    QMessageBox,
    QGroupBox,
    QSplitter,
    QComboBox,
    QCheckBox,
)

from git_manager.repository import RepositoryManager
from git_manager.explorer import RepositoryExplorer
from core.vscode_controller import VSCodeController
from core.typing_engine import TypingEngine
from core.background_client import (
    BackgroundClient,
    BridgeError,
    BridgeNotRunningError,
    BridgeConnectionError,
    BridgeConflictError,
    BridgeBadRequestError
)


# =====================================================
# HUMAN TYPING AUTOMATION WORKER (ORIGINAL ENGINE)
# =====================================================

class AutomationWorker(QThread):
    """
    Original Human-Style Keystroke Typing Automation Worker.
    Uses PyAutoGUI and window activation.
    """

    progress = Signal(int, int)
    message = Signal(str)
    completed = Signal()
    failed = Signal(str)
    stopped = Signal(str)

    def __init__(
        self,
        source_root,
        files,
        output_root,
        speed
    ):
        super().__init__()

        self.source_root = Path(source_root).resolve()
        self.files = files
        self.output_root = Path(output_root).resolve()

        self.speed = speed

        self.bot = TypingEngine(speed=speed)
        self.controller = VSCodeController()

        self.completed_count = 0

    def run(self):

        try:

            total = len(self.files)

            for index, source_file in enumerate(self.files, start=1):

                if self.isInterruptionRequested():
                    self.stopped.emit(
                        f"Stopped: {self.completed_count}/{total} files completed."
                    )
                    return

                source = Path(source_file).resolve()

                # Validate source path
                if not source.is_relative_to(self.source_root):
                    raise ValueError(
                        f"File outside repository: {source}"
                    )

                if not source.is_file():
                    raise FileNotFoundError(source)

                # Reject excessively large files
                if source.stat().st_size > 2 * 1024 * 1024:
                    raise ValueError(
                        f"File exceeds 2 MB: {source.name}"
                    )

                # Read source content
                try:
                    content = source.read_text(
                        encoding="utf-8"
                    )
                except UnicodeDecodeError as error:
                    raise ValueError(
                        f"Unsupported file encoding: {source.name}"
                    ) from error

                relative_path = source.relative_to(
                    self.source_root
                )

                destination = self.output_root / relative_path

                destination.parent.mkdir(
                    parents=True,
                    exist_ok=True
                )

                # Protect existing files
                if destination.exists():

                    if destination.is_symlink():
                        raise ValueError(
                            f"Symbolic link destination rejected: {destination}"
                        )

                    if destination.stat().st_size > 0:
                        raise FileExistsError(
                            f"Destination already contains data: {destination}"
                        )

                self.message.emit(
                    f"Preparing file {index}/{total}: {relative_path}"
                )

                # Create destination file
                destination.touch(exist_ok=True)

                # Open destination in VS Code
                self.controller.prepare_file(destination)

                # Countdown
                for remaining in range(5, 0, -1):

                    if self.isInterruptionRequested():
                        self.stopped.emit(
                            f"Stopped: {self.completed_count}/{total} files completed."
                        )
                        return

                    self.message.emit(
                        f"Starting {relative_path} in {remaining} seconds..."
                    )

                    time.sleep(1)

                if self.isInterruptionRequested():
                    self.stopped.emit(
                        f"Stopped: {self.completed_count}/{total} files completed."
                    )
                    return

                # Type source content
                self.message.emit(
                    f"Typing: {relative_path}"
                )

                result = self.bot.type_text(content)

                if not result:

                    if self.isInterruptionRequested():
                        self.stopped.emit(
                            f"Stopped: {self.completed_count}/{total} files completed."
                        )
                        return

                    raise RuntimeError(
                        f"Typing failed: {relative_path}"
                    )

                # Save destination file
                pyautogui.hotkey("ctrl", "s")

                time.sleep(1)

                # Verify actual saved content
                actual_content = destination.read_text(
                    encoding="utf-8"
                )

                if actual_content != content:

                    raise RuntimeError(
                        f"Verification failed: {relative_path}\n"
                        f"Expected: {len(content)} characters\n"
                        f"Actual: {len(actual_content)} characters\n"
                        "The destination may contain incomplete content."
                    )

                # Mark file complete
                self.completed_count += 1

                self.progress.emit(
                    self.completed_count,
                    total
                )

                self.message.emit(
                    f"Verified successfully: {relative_path}"
                )

            if self.completed_count == total:
                self.completed.emit()

        except Exception as error:

            self.failed.emit(str(error))

    def pause_typing(self):
        self.bot.pause()

    def resume_typing(self):
        self.bot.resume()

    def stop_typing(self):

        self.requestInterruption()
        self.bot.stop()


# =====================================================
# BACKGROUND AUTOMATION WORKER (VS CODE BRIDGE)
# =====================================================

class BackgroundAutomationWorker(QThread):
    """
    True Background Automation Worker using the VS Code Extension Bridge.
    Operates without PyAutoGUI, without mouse movement, and without stealing OS focus.
    """

    progress = Signal(int, int)
    message = Signal(str)
    file_started = Signal(str, int, int)
    file_completed = Signal(str)
    file_failed = Signal(str, str)
    completed = Signal(dict)
    failed = Signal(str)
    stopped = Signal(str)
    paused = Signal(bool)

    def __init__(
        self,
        source_root,
        files,
        output_root,
        speed=0.03,
        mode="incremental",
        overwrite=False
    ):
        super().__init__()

        self.source_root = Path(source_root).resolve()
        self.files = files
        self.output_root = Path(output_root).resolve()
        self.speed = speed
        self.mode = mode
        self.overwrite = overwrite

        self.client = BackgroundClient()
        self.completed_count = 0
        self.stop_requested = False
        self.is_paused = False

        self.completed_files = []
        self.failed_files = []
        self.pending_files = []

    def run(self):
        try:
            # 1. Health check to ensure VS Code bridge is ready
            try:
                health = self.client.health(timeout=3.0)
                if not health.get("success"):
                    raise RuntimeError("VS Code Background Bridge returned an invalid status.")
            except Exception as conn_err:
                self.failed.emit(
                    f"Cannot communicate with VS Code Background Engine:\n{conn_err}\n\n"
                    "Please ensure VS Code is open with the GitType extension active."
                )
                return

            total = len(self.files)
            self.completed_count = 0
            self.completed_files = []
            self.failed_files = []
            self.pending_files = [
                str(Path(f).resolve().relative_to(self.source_root))
                for f in self.files
            ]

            for index, source_file in enumerate(self.files, start=1):
                if self.isInterruptionRequested() or self.stop_requested:
                    self.stopped.emit(
                        f"Stopped: {self.completed_count}/{total} files completed."
                    )
                    return

                source = Path(source_file).resolve()
                relative_path = source.relative_to(self.source_root)
                rel_str = str(relative_path)

                # Validate source path
                if not source.is_relative_to(self.source_root):
                    self.failed_files.append((rel_str, "File outside repository"))
                    self.file_failed.emit(rel_str, "File outside repository")
                    continue

                if not source.is_file():
                    self.failed_files.append((rel_str, "File not found"))
                    self.file_failed.emit(rel_str, "File not found")
                    continue

                # Size limit
                if source.stat().st_size > 5 * 1024 * 1024:
                    self.failed_files.append((rel_str, "File exceeds 5 MB size limit"))
                    self.file_failed.emit(rel_str, "File exceeds 5 MB limit")
                    continue

                # Read source content
                try:
                    content = source.read_text(encoding="utf-8")
                except UnicodeDecodeError as ue:
                    self.failed_files.append((rel_str, f"Encoding error: {ue}"))
                    self.file_failed.emit(rel_str, f"Encoding error: {ue}")
                    continue

                destination = self.output_root / relative_path

                # Pre-check destination overwrite protection
                if destination.exists() and destination.stat().st_size > 0 and not self.overwrite:
                    err_msg = f"Destination already exists: {rel_str}. Set overwrite=true to replace."
                    self.failed_files.append((rel_str, err_msg))
                    self.file_failed.emit(rel_str, err_msg)
                    self.message.emit(f"Skipped {rel_str}: File exists and overwrite is disabled.")
                    continue

                self.file_started.emit(rel_str, index, total)
                self.message.emit(f"Background typing ({index}/{total}): {rel_str}")

                # Convert speed (seconds) to milliseconds for delay
                delay_ms = max(0, int(self.speed * 1000)) if self.mode == "incremental" else 0

                try:
                    result = self.client.type_text(
                        destination=destination,
                        content=content,
                        delay=delay_ms,
                        mode=self.mode,
                        overwrite=self.overwrite
                    )
                except Exception as type_err:
                    if self.isInterruptionRequested() or self.stop_requested:
                        self.stopped.emit(
                            f"Stopped: {self.completed_count}/{total} files completed."
                        )
                        return
                    err_str = str(type_err)
                    self.failed_files.append((rel_str, err_str))
                    self.file_failed.emit(rel_str, err_str)
                    self.message.emit(f"Error on {rel_str}: {err_str}")
                    continue

                if result.get("stopped"):
                    self.stopped.emit(
                        f"Stopped at {rel_str}: {self.completed_count}/{total} files completed."
                    )
                    return

                if result.get("success") and result.get("verified"):
                    self.completed_count += 1
                    self.completed_files.append(rel_str)
                    if rel_str in self.pending_files:
                        self.pending_files.remove(rel_str)
                    self.progress.emit(self.completed_count, total)
                    self.file_completed.emit(rel_str)
                    self.message.emit(f"Verified successfully: {rel_str}")
                else:
                    err_msg = result.get("message") or "Verification failed"
                    self.failed_files.append((rel_str, err_msg))
                    self.file_failed.emit(rel_str, err_msg)
                    self.message.emit(f"Verification failed: {rel_str}")

            summary = {
                "total": total,
                "completed": len(self.completed_files),
                "failed": len(self.failed_files),
                "completed_files": self.completed_files,
                "failed_files": self.failed_files,
                "mode": "Background Engine"
            }

            self.completed.emit(summary)

        except Exception as error:
            self.failed.emit(str(error))

    def pause_typing(self):
        self.is_paused = True
        try:
            self.client.pause()
            self.message.emit("Background typing paused.")
        except Exception as e:
            self.message.emit(f"Pause error: {e}")
        self.paused.emit(True)

    def resume_typing(self):
        self.is_paused = False
        try:
            self.client.resume()
            self.message.emit("Background typing resumed.")
        except Exception as e:
            self.message.emit(f"Resume error: {e}")
        self.paused.emit(False)

    def stop_typing(self):
        self.stop_requested = True
        self.requestInterruption()
        try:
            self.client.stop()
            self.message.emit("Stop signal sent to background engine.")
        except Exception as e:
            self.message.emit(f"Stop error: {e}")


# =====================================================
# GITTYPE DESKTOP DASHBOARD
# =====================================================

class GitTypeDashboard(QMainWindow):

    def __init__(self):

        super().__init__()

        self.setWindowTitle(
            "GitType - Repository Automation"
        )

        self.resize(1150, 820)

        self.manager = RepositoryManager()
        self.background_client = BackgroundClient()

        self.repository_path = None
        self.worker = None

        self.updating_tree = False
        self.automation_running = False

        self.build_interface()

        # Check bridge connection on startup
        self.check_bridge_connection(show_dialog=False)

    # =================================================
    # BUILD INTERFACE
    # =================================================

    def build_interface(self):

        central = QWidget()
        self.setCentralWidget(central)

        main_layout = QVBoxLayout(central)
        main_layout.setSpacing(12)

        # Header
        title = QLabel("GitType")
        title.setObjectName("title")

        subtitle = QLabel(
            "Git Repository to Visual Studio Code Automation"
        )

        main_layout.addWidget(title)
        main_layout.addWidget(subtitle)

        # Repository connection
        repository_group = QGroupBox(
            "Repository Connection"
        )

        repository_layout = QVBoxLayout(
            repository_group
        )

        self.repository_input = QLineEdit()
        self.repository_input.setPlaceholderText(
            "Enter Git URL or local repository path"
        )

        connection_buttons = QHBoxLayout()

        self.connect_button = QPushButton(
            "Clone Remote"
        )

        self.local_button = QPushButton(
            "Open Local"
        )

        self.connect_button.clicked.connect(
            self.clone_repository
        )

        self.local_button.clicked.connect(
            self.open_local
        )

        connection_buttons.addWidget(
            self.connect_button
        )

        connection_buttons.addWidget(
            self.local_button
        )

        repository_layout.addWidget(
            self.repository_input
        )

        repository_layout.addLayout(
            connection_buttons
        )

        main_layout.addWidget(
            repository_group
        )

        # Main splitter
        splitter = QSplitter(
            Qt.Orientation.Horizontal
        )

        # Repository explorer
        explorer_group = QGroupBox(
            "Repository Explorer"
        )

        explorer_layout = QVBoxLayout(
            explorer_group
        )

        self.tree = QTreeWidget()
        self.tree.setHeaderLabels(
            ["Repository Files"]
        )

        self.tree.itemChanged.connect(
            self.handle_item_changed
        )

        explorer_layout.addWidget(
            self.tree
        )

        splitter.addWidget(
            explorer_group
        )

        # Automation settings panel
        settings_panel = QWidget()
        settings_layout = QVBoxLayout(settings_panel)
        settings_layout.setContentsMargins(0, 0, 0, 0)

        settings_group = QGroupBox("Automation Settings")
        settings_form = QVBoxLayout(settings_group)
        settings_form.setSpacing(8)

        # Mode Selection
        settings_form.addWidget(QLabel("Automation Mode"))
        self.mode_combo = QComboBox()
        self.mode_combo.addItem("Background Mode (VS Code Extension Bridge)", "background")
        self.mode_combo.addItem("Human Typing Mode (Keystrokes / PyAutoGUI)", "human")
        self.mode_combo.currentIndexChanged.connect(self.on_mode_changed)
        settings_form.addWidget(self.mode_combo)

        # VS Code Bridge Status Container
        self.bridge_box = QGroupBox("VS Code Bridge Status")
        bridge_layout = QVBoxLayout(self.bridge_box)
        bridge_layout.setSpacing(6)

        bridge_status_row = QHBoxLayout()
        self.bridge_status_indicator = QLabel("Checking connection...")
        self.bridge_status_indicator.setStyleSheet("font-weight: bold; color: #f59e0b;")

        self.check_bridge_btn = QPushButton("Check Status")
        self.check_bridge_btn.clicked.connect(lambda: self.check_bridge_connection(show_dialog=True))

        bridge_status_row.addWidget(self.bridge_status_indicator)
        bridge_status_row.addWidget(self.check_bridge_btn)
        bridge_layout.addLayout(bridge_status_row)

        self.bridge_details_label = QLabel("Auto-detecting bridge port...")
        self.bridge_details_label.setStyleSheet("color: #9ca3af; font-size: 11px;")
        bridge_layout.addWidget(self.bridge_details_label)

        settings_form.addWidget(self.bridge_box)

        # Background Style Selector
        self.bg_style_label = QLabel("Background Typing Style")
        settings_form.addWidget(self.bg_style_label)

        self.bg_style_combo = QComboBox()
        self.bg_style_combo.addItem("Incremental (Human Pacing via VS Code API)", "incremental")
        self.bg_style_combo.addItem("Fast (Atomic Document Insertion)", "fast")
        settings_form.addWidget(self.bg_style_combo)

        # Speed / Delay Input
        self.speed_label = QLabel("Typing delay per chunk (seconds)")
        settings_form.addWidget(self.speed_label)

        self.speed_input = QDoubleSpinBox()
        self.speed_input.setRange(0.000, 2.0)
        self.speed_input.setSingleStep(0.01)
        self.speed_input.setValue(0.03)
        settings_form.addWidget(self.speed_input)

        # Destination Workspace
        settings_form.addWidget(QLabel("Destination workspace folder"))

        destination_row = QHBoxLayout()
        self.destination_input = QLineEdit(
            str(Path("output").resolve())
        )
        self.destination_button = QPushButton("Browse")
        self.destination_button.clicked.connect(self.choose_destination)

        destination_row.addWidget(self.destination_input)
        destination_row.addWidget(self.destination_button)
        settings_form.addLayout(destination_row)

        # Overwrite Checkbox
        self.overwrite_checkbox = QCheckBox("Overwrite existing destination files")
        self.overwrite_checkbox.setChecked(False)
        settings_form.addWidget(self.overwrite_checkbox)

        settings_layout.addWidget(settings_group)
        settings_layout.addStretch()

        splitter.addWidget(settings_panel)
        splitter.setSizes([680, 420])

        main_layout.addWidget(splitter)

        # Automation Monitor
        self.monitor_group = QGroupBox("Automation Monitor")
        monitor_layout = QVBoxLayout(self.monitor_group)
        monitor_layout.setSpacing(4)

        self.current_file_label = QLabel("Current File: None")
        self.current_file_label.setStyleSheet("font-weight: bold; color: #4ec9b0;")

        self.stats_label = QLabel("Completed: 0 | Pending: 0 | Failed: 0")
        self.stats_label.setStyleSheet("color: #d4d4d4;")

        monitor_layout.addWidget(self.current_file_label)
        monitor_layout.addWidget(self.stats_label)

        main_layout.addWidget(self.monitor_group)

        # Progress
        self.progress_bar = QProgressBar()
        self.progress_bar.setValue(0)
        main_layout.addWidget(self.progress_bar)

        self.status_label = QLabel("Waiting for repository...")
        main_layout.addWidget(self.status_label)

        # Automation controls
        controls = QHBoxLayout()

        self.start_button = QPushButton("Start Automation")
        self.pause_button = QPushButton("Pause")
        self.resume_button = QPushButton("Resume")
        self.stop_button = QPushButton("Stop")

        self.start_button.clicked.connect(self.start_automation)
        self.pause_button.clicked.connect(self.pause_automation)
        self.resume_button.clicked.connect(self.resume_automation)
        self.stop_button.clicked.connect(self.stop_automation)

        controls.addWidget(self.start_button)
        controls.addWidget(self.pause_button)
        controls.addWidget(self.resume_button)
        controls.addWidget(self.stop_button)

        main_layout.addLayout(controls)

        # Activity log
        main_layout.addWidget(QLabel("Activity Log"))

        self.log = QTextEdit()
        self.log.setReadOnly(True)
        self.log.setMaximumHeight(140)

        main_layout.addWidget(self.log)

        # Styling
        self.setStyleSheet("""

            QMainWindow {
                background: #17191f;
                color: #eeeeee;
            }

            QWidget {
                color: #eeeeee;
                font-size: 13px;
            }

            QLabel#title {
                font-size: 30px;
                font-weight: bold;
                color: #ffffff;
            }

            QGroupBox {
                border: 1px solid #353945;
                border-radius: 7px;
                margin-top: 10px;
                padding: 10px;
            }

            QGroupBox::title {
                subcontrol-origin: margin;
                left: 10px;
                padding: 0 5px;
            }

            QLineEdit,
            QDoubleSpinBox,
            QTextEdit,
            QTreeWidget,
            QComboBox {
                background: #22252e;
                border: 1px solid #353945;
                border-radius: 5px;
                padding: 7px;
                color: #eeeeee;
            }

            QComboBox::drop-down {
                border: none;
                width: 20px;
            }

            QComboBox QAbstractItemView {
                background: #22252e;
                color: #eeeeee;
                selection-background-color: #303642;
                border: 1px solid #353945;
            }

            QCheckBox {
                color: #eeeeee;
                padding: 3px 0;
            }

            QPushButton {
                background: #303642;
                border: none;
                border-radius: 5px;
                padding: 10px 15px;
            }

            QPushButton:hover {
                background: #465166;
            }

            QPushButton:disabled {
                background: #242831;
                color: #777777;
            }

            QProgressBar {
                background: #22252e;
                border: none;
                border-radius: 5px;
                text-align: center;
                height: 18px;
            }

            QProgressBar::chunk {
                background: #2d9c72;
                border-radius: 5px;
            }

        """)

        self.set_controls_running(False)

    # =================================================
    # MODE SWITCHING & BRIDGE CONNECTION
    # =================================================

    def on_mode_changed(self, index):
        mode_key = self.mode_combo.currentData()
        if mode_key == "background":
            self.bridge_box.setVisible(True)
            self.bg_style_label.setVisible(True)
            self.bg_style_combo.setVisible(True)
            self.speed_label.setText("Typing delay per chunk (seconds)")
            self.speed_input.setValue(0.03)
            self.check_bridge_connection(show_dialog=False)
            self.add_log("Selected Background Mode: Edits files directly in VS Code without keyboard simulation.")
        else:
            self.bridge_box.setVisible(False)
            self.bg_style_label.setVisible(False)
            self.bg_style_combo.setVisible(False)
            self.speed_label.setText("Typing delay per character (seconds)")
            self.speed_input.setValue(0.08)
            self.add_log("Selected Human Typing Mode: Uses physical keystroke simulation into active VS Code editor.")

    def check_bridge_connection(self, show_dialog=False):
        """Check if the VS Code background bridge is listening and active."""
        connected, msg, details = self.background_client.is_connected()
        if connected:
            cfg = self.background_client._cached_config or {}
            port = cfg.get("port") or details.get("port", "unknown")
            self.bridge_status_indicator.setText(f"🟢 Connected (Port {port})")
            self.bridge_status_indicator.setStyleSheet("font-weight: bold; color: #10b981;")

            ws = details.get("workspace") or cfg.get("workspace") or "GitType"
            ws_name = Path(ws).name
            self.bridge_details_label.setText(f"Workspace: {ws_name} | Host: 127.0.0.1:{port}")

            if show_dialog:
                QMessageBox.information(
                    self,
                    "Bridge Connected",
                    f"Successfully connected to GitType Background Engine!\n\n"
                    f"Listening: 127.0.0.1:{port}\n"
                    f"Workspace: {ws}\n"
                    f"Service: {details.get('service', 'GitType Background Engine')}"
                )
        else:
            self.bridge_status_indicator.setText("🔴 Disconnected")
            self.bridge_status_indicator.setStyleSheet("font-weight: bold; color: #ef4444;")
            self.bridge_details_label.setText(msg)

            if show_dialog:
                QMessageBox.warning(
                    self,
                    "Bridge Disconnected",
                    f"Could not connect to the VS Code Background Engine:\n\n{msg}\n\n"
                    "Make sure VS Code is open with the GitType Background Engine extension active."
                )

    # =================================================
    # UI HELPERS
    # =================================================

    def add_log(self, message):
        self.log.append(message)
        self.status_label.setText(message)

    def set_controls_running(self, running):
        self.automation_running = running

        self.start_button.setEnabled(not running)
        self.connect_button.setEnabled(not running)
        self.local_button.setEnabled(not running)
        self.tree.setEnabled(not running)
        self.destination_button.setEnabled(not running)
        self.speed_input.setEnabled(not running)
        self.mode_combo.setEnabled(not running)
        self.bg_style_combo.setEnabled(not running)
        self.overwrite_checkbox.setEnabled(not running)

        self.pause_button.setEnabled(running)
        self.resume_button.setEnabled(running)
        self.stop_button.setEnabled(running)

    # =================================================
    # REPOSITORY CONNECTION
    # =================================================

    def clone_repository(self):

        url = self.repository_input.text().strip()

        if not url:
            QMessageBox.warning(
                self,
                "Missing URL",
                "Enter a repository URL."
            )
            return

        try:
            result = self.manager.clone_repository(
                url
            )
            self.load_repository(
                result["path"]
            )
            self.add_log(
                f"Repository cloned: {result['name']}"
            )
        except Exception as error:
            QMessageBox.critical(
                self,
                "Clone Error",
                str(error)
            )

    def open_local(self):

        path = QFileDialog.getExistingDirectory(
            self,
            "Select Local Git Repository"
        )

        if not path:
            return

        try:
            result = self.manager.open_local_repository(
                path
            )
            self.repository_input.setText(
                result["path"]
            )
            self.load_repository(
                result["path"]
            )
            self.add_log(
                f"Local repository loaded: {result['name']}"
            )
        except Exception as error:
            QMessageBox.critical(
                self,
                "Repository Error",
                str(error)
            )

    # =================================================
    # REPOSITORY EXPLORER
    # =================================================

    def load_repository(self, path):

        self.repository_path = Path(
            path
        ).resolve()

        explorer = RepositoryExplorer(
            self.repository_path
        )

        self.updating_tree = True
        self.tree.clear()

        def add_entries(parent, entries):

            for entry in entries:

                item = QTreeWidgetItem(
                    [entry["name"]]
                )

                item.setData(
                    0,
                    Qt.ItemDataRole.UserRole,
                    entry["path"]
                )

                item.setFlags(
                    item.flags()
                    | Qt.ItemFlag.ItemIsUserCheckable
                )

                item.setCheckState(
                    0,
                    Qt.CheckState.Unchecked
                )

                if parent:
                    parent.addChild(item)
                else:
                    self.tree.addTopLevelItem(item)

                if entry["type"] == "directory":
                    add_entries(
                        item,
                        entry.get("children", [])
                    )

        add_entries(
            None,
            explorer.get_tree()
        )

        self.updating_tree = False
        self.add_log(
            "Repository explorer loaded."
        )

    def handle_item_changed(self, item, column):

        if self.updating_tree:
            return

        self.updating_tree = True

        state = item.checkState(0)

        def update_children(parent, new_state):

            for index in range(parent.childCount()):

                child = parent.child(index)
                child.setCheckState(
                    0,
                    new_state
                )
                update_children(
                    child,
                    new_state
                )

        if state != Qt.CheckState.PartiallyChecked:
            update_children(
                item,
                state
            )

        parent = item.parent()

        while parent:

            states = [
                parent.child(i).checkState(0)
                for i in range(parent.childCount())
            ]

            if all(
                s == Qt.CheckState.Checked
                for s in states
            ):
                parent.setCheckState(
                    0,
                    Qt.CheckState.Checked
                )
            elif all(
                s == Qt.CheckState.Unchecked
                for s in states
            ):
                parent.setCheckState(
                    0,
                    Qt.CheckState.Unchecked
                )
            else:
                parent.setCheckState(
                    0,
                    Qt.CheckState.PartiallyChecked
                )

            parent = parent.parent()

        self.updating_tree = False

    def get_selected_files(self):

        selected = []

        def scan(item):

            path = Path(
                item.data(
                    0,
                    Qt.ItemDataRole.UserRole
                )
            )

            if (
                path.is_file()
                and item.checkState(0) == Qt.CheckState.Checked
            ):
                selected.append(
                    str(path)
                )

            for index in range(item.childCount()):
                scan(
                    item.child(index)
                )

        for index in range(
            self.tree.topLevelItemCount()
        ):
            scan(
                self.tree.topLevelItem(index)
            )

        return selected

    # =================================================
    # DESTINATION
    # =================================================

    def choose_destination(self):

        path = QFileDialog.getExistingDirectory(
            self,
            "Choose Destination Workspace"
        )

        if path:
            self.destination_input.setText(
                path
            )

    # =================================================
    # START AUTOMATION
    # =================================================

    def start_automation(self):

        if self.automation_running:
            return

        if not self.repository_path:
            QMessageBox.warning(
                self,
                "No Repository",
                "Connect a repository first."
            )
            return

        files = self.get_selected_files()

        if not files:
            QMessageBox.warning(
                self,
                "No Files",
                "Select at least one file from the repository explorer."
            )
            return

        output = Path(
            self.destination_input.text()
        ).expanduser().resolve()

        if (
            output == self.repository_path
            or self.repository_path in output.parents
        ):
            QMessageBox.warning(
                self,
                "Unsafe Destination",
                "Destination must be outside the source repository."
            )
            return

        # Check existing destination files
        conflicts = []
        for source_file in files:
            source = Path(source_file).resolve()
            if not source.is_relative_to(self.repository_path):
                QMessageBox.warning(
                    self,
                    "Invalid File",
                    f"Selected file is outside repository: {source}"
                )
                return

            relative_path = source.relative_to(self.repository_path)
            destination = output / relative_path

            if destination.is_symlink():
                QMessageBox.warning(
                    self,
                    "Unsafe Destination",
                    f"Symbolic link destination rejected:\n{destination}"
                )
                return

            if destination.exists() and destination.stat().st_size > 0:
                conflicts.append(str(relative_path))

        # Handle destination conflicts with user prompt
        if conflicts and not self.overwrite_checkbox.isChecked():
            answer = QMessageBox.question(
                self,
                "Existing Files Detected",
                f"These {len(conflicts)} destination files already contain data:\n\n"
                + "\n".join(conflicts[:8]) + ("\n..." if len(conflicts) > 8 else "")
                + "\n\nDo you want to overwrite existing files and proceed?",
                QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.No,
                QMessageBox.StandardButton.No
            )
            if answer == QMessageBox.StandardButton.Yes:
                self.overwrite_checkbox.setChecked(True)
            else:
                return

        mode_key = self.mode_combo.currentData()

        # Confirmation
        mode_title = "Background Mode" if mode_key == "background" else "Human Typing Mode"
        confirm_msg = (
            f"Process {len(files)} selected files?\n\n"
            f"Mode: {mode_title}\n"
            f"Destination: {output}\n\n"
        )
        if mode_key == "background":
            confirm_msg += "GitType will edit documents directly through the VS Code Extension Bridge.\nDesktop mouse and keyboard will NOT be locked."
        else:
            confirm_msg += "GitType will simulate physical keystrokes into the active VS Code window.\nDo not interact with mouse or keyboard during typing."

        answer = QMessageBox.question(
            self,
            "Confirm Automation",
            confirm_msg,
            QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.No
        )

        if answer != QMessageBox.StandardButton.Yes:
            return

        output.mkdir(
            parents=True,
            exist_ok=True
        )

        self.progress_bar.setValue(0)
        self.set_controls_running(True)

        if mode_key == "background":
            # 1. Verify Bridge Connection before launch
            connected, msg, details = self.background_client.is_connected()
            if not connected:
                self.set_controls_running(False)
                res = QMessageBox.question(
                    self,
                    "VS Code Bridge Not Detected",
                    f"Cannot communicate with VS Code Background Engine:\n\n{msg}\n\n"
                    "Make sure VS Code is open with the GitType extension active.\n"
                    "Would you like to re-check the connection now?",
                    QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.No,
                    QMessageBox.StandardButton.Yes
                )
                if res == QMessageBox.StandardButton.Yes:
                    self.check_bridge_connection(show_dialog=True)
                return

            self.current_file_label.setText(f"Preparing {len(files)} files...")
            self.stats_label.setText(f"Completed: 0 | Pending: {len(files)} | Failed: 0")

            bg_style = self.bg_style_combo.currentData() or "incremental"
            self.worker = BackgroundAutomationWorker(
                source_root=self.repository_path,
                files=files,
                output_root=output,
                speed=self.speed_input.value(),
                mode=bg_style,
                overwrite=self.overwrite_checkbox.isChecked()
            )

            self.worker.file_started.connect(self.on_file_started)
            self.worker.file_completed.connect(self.on_file_completed)
            self.worker.file_failed.connect(self.on_file_failed)

        else:
            # Human Typing Mode
            self.current_file_label.setText(f"Preparing {len(files)} files for Human Typing...")
            self.stats_label.setText(f"Completed: 0 | Pending: {len(files)} | Failed: 0")

            self.worker = AutomationWorker(
                self.repository_path,
                files,
                output,
                self.speed_input.value()
            )

        self.worker.message.connect(self.add_log)
        self.worker.progress.connect(self.update_progress)
        self.worker.completed.connect(self.finish_automation)
        self.worker.failed.connect(self.show_error)
        self.worker.stopped.connect(self.finish_stopped)
        self.worker.finished.connect(self.worker_finished)

        self.worker.start()
        self.add_log(f"Automation started in {mode_title}.")

    # =================================================
    # PROGRESS & FILE MONITORING
    # =================================================

    def on_file_started(self, relative_path, index, total):
        self.current_file_label.setText(f"Current File: {relative_path} ({index}/{total})")
        if self.worker and hasattr(self.worker, "pending_files"):
            c = len(self.worker.completed_files)
            p = len(self.worker.pending_files)
            f = len(self.worker.failed_files)
            self.stats_label.setText(f"Completed: {c} | Pending: {p} | Failed: {f}")

    def on_file_completed(self, relative_path):
        if self.worker and hasattr(self.worker, "pending_files"):
            c = len(self.worker.completed_files)
            p = len(self.worker.pending_files)
            f = len(self.worker.failed_files)
            self.stats_label.setText(f"Completed: {c} | Pending: {p} | Failed: {f}")

    def on_file_failed(self, relative_path, error_str):
        if self.worker and hasattr(self.worker, "pending_files"):
            c = len(self.worker.completed_files)
            p = len(self.worker.pending_files)
            f = len(self.worker.failed_files)
            self.stats_label.setText(f"Completed: {c} | Pending: {p} | Failed: {f}")
        self.add_log(f"FILE FAILED: {relative_path} — {error_str}")

    def update_progress(self, current, total):

        percentage = int(
            current / total * 100
        )

        self.progress_bar.setValue(
            percentage
        )

        self.add_log(
            f"Progress: {current}/{total} files verified."
        )

    # =================================================
    # PAUSE / RESUME / STOP
    # =================================================

    def pause_automation(self):

        if self.worker and self.worker.isRunning():
            self.worker.pause_typing()
            self.add_log(
                "Typing paused."
            )

    def resume_automation(self):

        if self.worker and self.worker.isRunning():
            self.worker.resume_typing()
            self.add_log(
                "Typing resumed."
            )

    def stop_automation(self):

        if self.worker and self.worker.isRunning():
            self.worker.stop_typing()
            self.add_log(
                "Stop requested. Waiting for current operation to halt safely..."
            )

    # =================================================
    # COMPLETION / ERRORS
    # =================================================

    def finish_automation(self, summary=None):

        self.progress_bar.setValue(100)

        if isinstance(summary, dict):
            completed_n = summary.get("completed", 0)
            total_n = summary.get("total", 0)
            failed_n = summary.get("failed", 0)
            mode_name = summary.get("mode", "Background Engine")

            self.current_file_label.setText("Automation Completed")
            self.stats_label.setText(f"Completed: {completed_n}/{total_n} | Failed: {failed_n}")

            summary_msg = (
                f"Mode: {mode_name}\n"
                f"Total Files: {total_n}\n"
                f"Successfully Verified: {completed_n}\n"
                f"Failed Files: {failed_n}\n\n"
            )

            if summary.get("failed_files"):
                summary_msg += "Failed details:\n"
                for path, err in summary["failed_files"][:5]:
                    summary_msg += f" • {path}: {err}\n"
                if len(summary["failed_files"]) > 5:
                    summary_msg += f" • ... and {len(summary['failed_files']) - 5} more\n"
                summary_msg += "\nCheck the Activity Log for full details."
            else:
                summary_msg += "All requested files were created, typed and verified with 100% integrity!"

            self.add_log(f"SUMMARY: {completed_n}/{total_n} files verified successfully.")
            QMessageBox.information(
                self,
                "GitType - Automation Completed",
                summary_msg
            )
        else:
            self.current_file_label.setText("Automation Completed")
            self.add_log(
                "SUCCESS: All selected files were typed and verified."
            )
            QMessageBox.information(
                self,
                "GitType - Completed",
                "All selected files were successfully typed and verified."
            )

    def finish_stopped(self, message):

        self.add_log(
            message
        )
        self.current_file_label.setText("Automation Stopped")

        QMessageBox.information(
            self,
            "GitType - Stopped",
            message
        )

    def show_error(self, error):

        self.add_log(
            f"ERROR: {error}"
        )
        self.current_file_label.setText("Automation Failed")

        QMessageBox.critical(
            self,
            "Automation Error",
            str(error)
        )

    def worker_finished(self):

        self.set_controls_running(False)

    # =================================================
    # CLOSE APPLICATION
    # =================================================

    def closeEvent(self, event):

        if self.worker and self.worker.isRunning():
            self.worker.stop_typing()
            self.worker.wait()

        event.accept()


# =====================================================
# APPLICATION ENTRY
# =====================================================

def main():

    app = QApplication(sys.argv)

    window = GitTypeDashboard()
    window.show()

    sys.exit(
        app.exec()
    )


if __name__ == "__main__":
    main()
