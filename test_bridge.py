
import json
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent
CONFIG = ROOT / ".gittype-bridge.json"


def main():

    print("=" * 40)
    print("       GITTYPE BRIDGE TEST")
    print("=" * 40)

    if not CONFIG.exists():
        print("ERROR: Bridge configuration not found.")
        print("Make sure the Extension Development Host is running.")
        return

    config = json.loads(
        CONFIG.read_text(encoding="utf-8")
    )

    url = f"http://{config['host']}:{config['port']}/health"

    try:

        request = urllib.request.Request(url)

        with urllib.request.urlopen(
            request,
            timeout=5
        ) as response:

            result = json.loads(
                response.read().decode("utf-8")
            )

        print("Connection successful!")
        print("Service:", result["service"])
        print("Active:", result["active"])
        print("Typing:", result["typing"])

        print("\nGITTYPE BRIDGE PASSED")

    except Exception as error:

        print("BRIDGE ERROR:", error)


if __name__ == "__main__":
    main()
