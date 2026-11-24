
import re
from pathlib import Path
from urllib.parse import urlparse

from git import Repo, GitCommandError, InvalidGitRepositoryError


class RepositoryManager:
    """Fetch and manage remote and local Git repositories."""

    def __init__(self, storage_directory="repositories"):
        self.storage = Path(storage_directory).resolve()
        self.storage.mkdir(parents=True, exist_ok=True)

    def validate_url(self, url):
        """Validate supported Git remote URL formats."""

        url = url.strip()

        if url.startswith("git@"):
            if ":" not in url:
                raise ValueError("Invalid SSH repository URL.")
            return url

        parsed = urlparse(url)

        if parsed.scheme not in ("https", "http", "ssh"):
            raise ValueError("Unsupported repository URL scheme.")

        if not parsed.netloc:
            raise ValueError("Repository URL has no hostname.")

        return url

    def repository_name(self, url):
        """Generate a safe local folder name."""

        name = url.rstrip("/").split("/")[-1]
        name = re.sub(r"\.git$", "", name)
        name = re.sub(r"[^a-zA-Z0-9._-]", "_", name)

        if not name:
            raise ValueError("Could not determine repository name.")

        return name

    def clone_repository(self, url, branch=None):
        """Clone a remote Git repository."""

        url = self.validate_url(url)

        destination = self.storage / self.repository_name(url)

        if destination.exists():
            raise FileExistsError(
                f"Destination already exists: {destination}"
            )

        try:
            print("Cloning repository...")

            repo = Repo.clone_from(
                url,
                destination,
                branch=branch if branch else None,
                progress=None
            )

            print("Repository cloned successfully.")

            return {
                "path": str(destination),
                "branch": repo.active_branch.name,
                "name": destination.name
            }

        except GitCommandError as error:
            raise RuntimeError(
                f"Git clone failed: {error}"
            ) from error

    def open_local_repository(self, path):
        """Load an existing local Git repository."""

        location = Path(path).expanduser().resolve()

        if not location.exists():
            raise FileNotFoundError(location)

        try:
            repo = Repo(location, search_parent_directories=True)

        except InvalidGitRepositoryError as error:
            raise ValueError(
                "The selected folder is not a Git repository."
            ) from error

        return {
            "path": str(Path(repo.working_tree_dir).resolve()),
            "name": Path(repo.working_tree_dir).name,
            "branch": (
                repo.active_branch.name
                if not repo.head.is_detached
                else "DETACHED HEAD"
            )
        }

    def get_branches(self, repository_path):
        """Return available local branches."""

        repo = Repo(repository_path)

        return [branch.name for branch in repo.branches]

    def checkout_branch(self, repository_path, branch):
        """Switch to an existing local branch."""

        repo = Repo(repository_path)

        if branch not in [item.name for item in repo.branches]:
            raise ValueError(f"Branch not found: {branch}")

        if repo.is_dirty(untracked_files=True):
            raise RuntimeError(
                "Repository has uncommitted changes. "
                "Commit or safely stash them before switching."
            )

        repo.git.checkout(branch)

        return branch

    def update_repository(self, repository_path):
        """Fetch remote changes without overwriting local files."""

        repo = Repo(repository_path)

        if repo.is_dirty(untracked_files=True):
            raise RuntimeError(
                "Repository has local changes. "
                "Commit or safely stash them before updating."
            )

        if not repo.remotes:
            raise RuntimeError("No remote configured.")

        for remote in repo.remotes:
            remote.fetch()

        return "Remote changes fetched successfully."
