from cryptography.fernet import Fernet, InvalidToken
from django.conf import settings


class CredentialEncryptionError(Exception):
    pass


def _fernet() -> Fernet:
    key = settings.CREDENTIAL_ENCRYPTION_KEY
    if not key:
        raise CredentialEncryptionError(
            "CREDENTIAL_ENCRYPTION_KEY is not set. Generate one with:\n"
            "  python -c \"from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())\""
        )
    return Fernet(key.encode() if isinstance(key, str) else key)


def encrypt_secret(plain: str) -> bytes:
    if plain is None:
        return b""
    return _fernet().encrypt(plain.encode())


def decrypt_secret(token) -> str:
    if not token:
        return ""
    raw = bytes(token)
    try:
        return _fernet().decrypt(raw).decode()
    except InvalidToken as exc:
        raise CredentialEncryptionError("Could not decrypt stored credential — wrong key?") from exc
