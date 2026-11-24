import time
import threading
from pathlib import Path
from uuid import uuid4

PROJECT_ROOT = Path(__file__).resolve().parent
from core.background_client import BackgroundClient

def test_pause_resume_stop():
    print("=" * 60)
    print(" >>> TESTING PAUSE, RESUME, AND STOP CONTROLS")
    print("=" * 60)

    client = BackgroundClient(PROJECT_ROOT)
    dest_file = PROJECT_ROOT / "output" / f"pause_test_{uuid4().hex[:6]}.txt"
    long_content = "GitType background pause-and-resume control verification test line.\n" * 100

    job_thread = threading.Thread(
        target=lambda: client.type_text(dest_file, long_content, delay=40, mode="incremental", overwrite=True)
    )
    job_thread.start()

    # Wait for typing to begin
    time.sleep(0.5)
    st = client.status()
    print("Status during typing:", st.get("typing"))
    assert st.get("typing") is True, "Typing is actively running"

    # Pause
    print("Sending PAUSE...")
    p_res = client.pause()
    print("Pause response:", p_res)
    time.sleep(0.3)
    st = client.status()
    assert st.get("paused") is True, "Engine is in paused state"
    print("Engine confirmed paused.")

    # Resume
    print("Sending RESUME...")
    r_res = client.resume()
    print("Resume response:", r_res)
    time.sleep(0.3)
    st = client.status()
    assert st.get("paused") is False, "Engine resumed"
    print("Engine confirmed resumed.")

    # Stop
    print("Sending STOP...")
    s_res = client.stop()
    print("Stop response:", s_res)
    job_thread.join(timeout=5)
    assert not job_thread.is_alive(), "Job thread stopped cleanly"
    print("Job stopped cleanly.")

    print("\n [PASS] PAUSE, RESUME, AND STOP CONTROLS VERIFIED SUCCESSFULLY!")

if __name__ == "__main__":
    test_pause_resume_stop()
