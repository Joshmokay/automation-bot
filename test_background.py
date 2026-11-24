
from pathlib import Path
from uuid import uuid4

from core.background_client import BackgroundClient


ROOT = Path(__file__).resolve().parent

OUTPUT = ROOT / "output"

OUTPUT.mkdir(exist_ok=True)

DESTINATION = OUTPUT / f"background_demo_{uuid4().hex[:8]}.txt"


CONTENT = """GITTYPE BACKGROUND AUTOMATION TEST

This file is being created through the VS Code Extension API.

The Python application is communicating with the extension.

No physical keyboard simulation is being used.

No mouse movement is required.

Indentation test:

    First level
        Second level
            Third level

Background automation is working!

END OF TEST.
"""


def main():

    print("=" * 45)
    print("       GITTYPE BACKGROUND TYPING")
    print("=" * 45)

    client = BackgroundClient(ROOT)

    print("\nChecking extension connection...")

    status = client.health()

    print("Connected:", status["service"])

    print("\nSending document to VS Code...")

    result = client.type_text(
        DESTINATION,
        CONTENT,
        delay=100
    )

    if result.get("success") and result.get("verified"):

        print("\nBACKGROUND TEST PASSED")

        print("File:", result["file"])

        print("Characters:", result["characters"])

        print("Verified:", result["verified"])

    else:

        print("\nBACKGROUND TEST FAILED")

        print(result)


if __name__ == "__main__":
    main()
