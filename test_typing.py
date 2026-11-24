
import time

from core.typing_engine import TypingEngine


def main():

    bot = TypingEngine(speed=0.08)

    sample_code = '''def hello():
    print("Hello from GitType!")

hello()
'''

    print("================================")
    print("       GITTYPE TYPING TEST")
    print("================================")

    print("Open VS Code and select an EMPTY editor.")
    print("Typing begins in 10 seconds.")

    for count in range(10, 0, -1):
        print(f"Starting in {count}...")
        time.sleep(1)

    print("Typing started!")

    result = bot.type_text(sample_code)

    if result:
        print("TEST PASSED!")
    else:
        print("TEST DID NOT COMPLETE.")


if __name__ == "__main__":
    main()
