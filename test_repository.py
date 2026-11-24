
from git_manager.repository import RepositoryManager
from git_manager.explorer import RepositoryExplorer


def main():

    manager = RepositoryManager()

    print("================================")
    print("     GITTYPE REPOSITORY TEST")
    print("================================")

    print("\nChoose repository source:")
    print("1. Remote Git repository")
    print("2. Existing local repository")

    choice = input("\nEnter choice (1 or 2): ").strip()

    try:

        if choice == "1":

            url = input("Enter Git repository URL: ").strip()

            branch = input(
                "Enter branch (leave empty for default): "
            ).strip()

            result = manager.clone_repository(
                url,
                branch or None
            )

        elif choice == "2":

            path = input("Enter local repository path: ").strip()

            result = manager.open_local_repository(path)

        else:
            print("Invalid selection.")
            return

        print("\nREPOSITORY CONNECTED")
        print("Name:", result["name"])
        print("Path:", result["path"])
        print("Branch:", result["branch"])

        explorer = RepositoryExplorer(result["path"])

        files = explorer.get_files()

        print("\nDISCOVERED FILES:")

        for file in files[:30]:
            print(" -", file)

        print("\nTotal files:", len(files))

        branches = manager.get_branches(result["path"])

        print("\nAVAILABLE BRANCHES:")

        for branch in branches:
            print(" -", branch)

    except Exception as error:
        print("\nREPOSITORY ERROR:", error)


if __name__ == "__main__":
    main()
