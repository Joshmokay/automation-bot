
from pathlib import Path

from core.vscode_controller import VSCodeController


def main():

    controller = VSCodeController()

    test_file = Path("repositories/gittype_demo.py")

    test_file.parent.mkdir(
        parents=True,
        exist_ok=True
    )

    if not test_file.exists():
        test_file.write_text(
            "# GitType controller test\n",
            encoding="utf-8"
        )

    try:

        print("Starting VS Code controller...")

        controller.prepare_file(test_file)

        print("\nSUCCESS!")
        print("VS Code detected.")
        print("Test file opened.")
        print("Editor focused.")

    except Exception as error:

        print("\nCONTROLLER FAILED:")
        print(error)


if __name__ == "__main__":
    main()


