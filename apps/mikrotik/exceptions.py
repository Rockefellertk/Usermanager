class MikroTikError(Exception):
    """Base class for all MikroTik client errors."""


class MikroTikUnreachable(MikroTikError):
    """Connection/timeout/auth failure — the router could not be reached or rejected us."""


class MikroTikAPIError(MikroTikError):
    """The router responded, but with an error (bad request, duplicate name, etc.)."""

    def __init__(self, message, status_code=None, payload=None):
        super().__init__(message)
        self.status_code = status_code
        self.payload = payload
