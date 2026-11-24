
import ctypes
import shutil
import subprocess
import time
from pathlib import Path

import pyautogui


class VSCodeController:
    """Windows VS Code automation controller."""

    def __init__(self):
        self.user32 = ctypes.windll.user32

    def get_code_executable(self):
        """Locate the actual VS Code executable."""

        candidates = [
            Path.home() / "AppData/Local/Programs/Microsoft VS Code/Code.exe",
            Path("C:/Program Files/Microsoft VS Code/Code.exe"),
            Path("C:/Program Files (x86)/Microsoft VS Code/Code.exe"),
        ]

        for candidate in candidates:
            if candidate.exists():
                return str(candidate)

        command = shutil.which("code")

        if command:
            return command

        raise FileNotFoundError(
            "Could not locate VS Code. Check its installation."
        )

    def find_vscode_windows(self):
        """Find running VS Code windows."""

        windows = []

        callback_type = ctypes.WINFUNCTYPE(
            ctypes.c_bool,
            ctypes.c_void_p,
            ctypes.c_void_p
        )

        def callback(hwnd, extra):

            if not self.user32.IsWindowVisible(hwnd):
                return True

            length = self.user32.GetWindowTextLengthW(hwnd)

            if length == 0:
                return True

            buffer = ctypes.create_unicode_buffer(length + 1)

            self.user32.GetWindowTextW(
                hwnd, buffer, length + 1
            )

            title = buffer.value

            if "Visual Studio Code" in title:
                windows.append((hwnd, title))

            return True

        self.user32.EnumWindows(
            callback_type(callback), 0
        )

        return windows

    def activate_vscode(self):

        windows = self.find_vscode_windows()

        if not windows:
            raise RuntimeError(
                "No VS Code window detected."
            )

        hwnd, title = windows[0]

        self.user32.ShowWindow(hwnd, 9)

        self.user32.SetForegroundWindow(hwnd)

        time.sleep(0.5)

        if self.user32.GetForegroundWindow() != hwnd:
            raise RuntimeError(
                "Windows did not allow VS Code to take focus."
            )

        print("VS Code activated:", title)

        return True

    def open_file(self, file_path):

        path = Path(file_path).resolve()

        if not path.is_file():
            raise FileNotFoundError(
                f"Target file does not exist: {path}"
            )

        executable = self.get_code_executable()

        print("VS Code executable:", executable)

        # Launch the real executable directly when available.
        if executable.lower().endswith(".exe"):

            subprocess.Popen([
                executable,
                "--reuse-window",
                str(path)
            ])

        else:
            # Windows command wrappers such as code.cmd
            command = (
                f'"{executable}" --reuse-window "{path}"'
            )

            subprocess.Popen(
                command,
                shell=True
            )

        # Wait for VS Code to display its window.
        for _ in range(20):

            if self.find_vscode_windows():
                break

            time.sleep(0.5)

        else:
            raise RuntimeError(
                "VS Code window did not appear."
            )

        self.activate_vscode()

        print("Opened file:", path)

        return True

    def focus_editor(self):

        self.activate_vscode()

        pyautogui.hotkey("ctrl", "1")

        time.sleep(0.5)

        print("Editor focused.")

        return True

    def prepare_file(self, file_path):

        self.open_file(file_path)

        self.focus_editor()

        return True
