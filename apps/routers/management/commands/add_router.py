from django.core.management.base import BaseCommand, CommandError

from apps.routers.models import Mikrotik


class Command(BaseCommand):
    help = "Register a MikroTik router with the panel."

    def add_arguments(self, parser):
        parser.add_argument("--name", required=True)
        parser.add_argument("--host", required=True)
        parser.add_argument("--port", type=int, default=443)
        parser.add_argument("--username", required=True)
        parser.add_argument("--password", required=False, help="Omit to be prompted interactively")
        parser.add_argument("--api-key", required=False, help="RouterOS 7.13+ API key, used instead of --password")

    def handle(self, *args, **options):
        if Mikrotik.objects.filter(name=options["name"]).exists():
            raise CommandError(f"A router named '{options['name']}' already exists.")

        password = options.get("password")
        api_key = options.get("api_key")
        if not password and not api_key:
            import getpass
            password = getpass.getpass("Router password: ")

        mikrotik = Mikrotik(
            name=options["name"], host=options["host"], port=options["port"], username=options["username"],
            use_api_key=bool(api_key),
        )
        if api_key:
            mikrotik.set_api_key(api_key)
        else:
            mikrotik.set_password(password)
        mikrotik.save()
        self.stdout.write(self.style.SUCCESS(f"Added router '{mikrotik.name}' ({mikrotik.base_url})"))
