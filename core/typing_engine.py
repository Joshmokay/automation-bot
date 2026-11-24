
import time
import threading
import random

import pyautogui
import pyperclip


class TypingEngine:
    """
    GitType human-style typing engine.

    Features:
    - Character-by-character typing
    - Preserves spaces and indentation
    - Handles tabs and Unicode characters
    - Supports pause, resume and stop
    - Reports whether typing completed
    """

    def __init__(self, speed=0.08):

        self.speed = max(0.001, float(speed))

        # Thread control
        self.pause_event = threading.Event()
        self.pause_event.set()

        self.stop_event = threading.Event()

        # Prevent PyAutoGUI from adding extra delays
        pyautogui.PAUSE = 0

    # ==============================================
    # PAUSE
    # ==============================================

    def pause(self):
        """Pause typing."""

        self.pause_event.clear()

    # ==============================================
    # RESUME
    # ==============================================

    def resume(self):
        """Resume typing."""

        self.pause_event.set()

    # ==============================================
    # STOP
    # ==============================================

    def stop(self):
        """Stop typing immediately."""

        self.stop_event.set()

        # Release a paused thread so it can exit.
        self.pause_event.set()

    # ==============================================
    # WAIT FOR PERMISSION TO TYPE
    # ==============================================

    def wait_until_ready(self):

        while not self.stop_event.is_set():

            if self.pause_event.wait(timeout=0.1):
                return not self.stop_event.is_set()

        return False

    # ==============================================
    # TYPE ONE CHARACTER
    # ==============================================

    def type_character(self, character):

        if character == "\n":

            # Enter creates the next line.
            # VS Code auto-indent should be disabled.
            pyautogui.press("enter")

        elif character == "\t":

            # Paste a literal tab instead of pressing
            # the Tab key, which may trigger editor commands.
            pyperclip.copy("\t")
            pyautogui.hotkey("ctrl", "v")

        elif character.isprintable() and ord(character) < 128:

            # Type ordinary ASCII characters.
            # This preserves individual spaces.
            pyautogui.write(character, interval=0)

        else:

            # Clipboard fallback for Unicode characters.
            pyperclip.copy(character)
            pyautogui.hotkey("ctrl", "v")

    # ==============================================
    # TYPE COMPLETE TEXT
    # ==============================================

    def type_text(self, text):

        if not isinstance(text, str):
            raise TypeError("Typing input must be a string.")

        # Reset stop state for a new typing operation.
        self.stop_event.clear()
        self.pause_event.set()

        try:

            for character in text:

                # Check pause and stop before every character.
                if not self.wait_until_ready():
                    return False

                self.type_character(character)

                # Human-style typing delay with slight variation.
                delay = random.uniform(
                    self.speed * 0.85,
                    self.speed * 1.15
                )

                if self.stop_event.wait(delay):
                    return False

            return True

        except Exception:
            raise

    # ==============================================
    # STATUS
    # ==============================================

    def is_paused(self):
        return not self.pause_event.is_set()

    def is_stopped(self):
        return self.stop_event.is_set()
