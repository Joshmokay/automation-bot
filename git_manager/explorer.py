
from pathlib import Path


class RepositoryExplorer:
    """Explore files and directories inside a repository."""

    EXCLUDED_DIRECTORIES = {
        ".git",
        "__pycache__",
        ".venv",
        "venv",
        "env",
        ".env",
        "node_modules",
        "vendor",
        ".idea",
        ".vscode",
        ".pytest_cache",
        ".mypy_cache",
        ".tox",
        "build",
        "dist",
        ".coverage"
    }

    BINARY_EXTENSIONS = {
        ".png", ".jpg", ".jpeg", ".gif", ".ico", ".svgz", ".bmp", ".webp",
        ".exe", ".dll", ".so", ".dylib", ".bin", ".dat",
        ".zip", ".tar", ".gz", ".7z", ".rar",
        ".pdf", ".doc", ".docx", ".xls", ".xlsx",
        ".pyc", ".pyo", ".pyd", ".class", ".o", ".obj"
    }

    @classmethod
    def is_text_file(cls, file_path):
        """Check if file can be safely read as text."""
        path = Path(file_path)
        if not path.is_file():
            return False
        if path.suffix.lower() in cls.BINARY_EXTENSIONS:
            return False
        try:
            with open(path, "rb") as f:
                chunk = f.read(1024)
                if b"\x00" in chunk:
                    return False
            return True
        except Exception:
            return False

    def __init__(self, repository_path):
        self.root = Path(repository_path).resolve()

        if not self.root.is_dir():
            raise NotADirectoryError(self.root)

    def get_tree(self, directory=None):
        """Return a nested repository directory structure."""

        current = Path(directory).resolve() if directory else self.root

        if not current.is_relative_to(self.root):
            raise ValueError("Path is outside the repository.")

        if not current.is_dir():
            raise NotADirectoryError(current)

        result = []

        for item in sorted(
            current.iterdir(),
            key=lambda entry: (not entry.is_dir(), entry.name.lower())
        ):

            if item.is_symlink():
                continue

            if item.is_dir() and item.name in self.EXCLUDED_DIRECTORIES:
                continue

            entry = {
                "name": item.name,
                "path": str(item),
                "type": "directory" if item.is_dir() else "file"
            }

            if item.is_dir():
                entry["children"] = self.get_tree(item)

            result.append(entry)

        return result

    def get_files(self):
        """Return all eligible files recursively."""

        files = []

        def scan(directory):

            for item in directory.iterdir():

                if item.is_symlink():
                    continue

                if item.is_dir():

                    if item.name not in self.EXCLUDED_DIRECTORIES:
                        scan(item)

                elif item.is_file():
                    files.append(str(item))

        scan(self.root)

        return files

    def get_file_content(self, file_path):
        """Read a selected file safely."""

        path = Path(file_path).resolve()

        if not path.is_relative_to(self.root):
            raise ValueError("File is outside the repository.")

        if not path.is_file():
            raise FileNotFoundError(path)

        return path.read_text(encoding="utf-8")
