# Chocolatey

Installs the [Chocolatey](https://chocolatey.org/) package manager with its official install
script (`https://community.chocolatey.org/install.ps1`). Chocolatey needs .NET Framework 4.8:
where it is missing (Windows Server 2016), Microsoft's offline installer
(`go.microsoft.com/fwlink/?linkid=2088631`) runs first and the server restarts once. Written
for Windows Server 2016 to 2025 and Windows 10/11.

Output: the Chocolatey version.

- **Actions:** `install_package` installs a package from the community repository, or
  upgrades it (optionally to a given version, also an older one); `remove_package`
  uninstalls one with the dependencies it brought; `list_packages` lists the installed
  packages as `id version` lines. Package ids are refused unless they look like Chocolatey
  ids (letters, digits, dots, dashes, underscores) and are passed to `choco` as one
  argument.
- **Restarts:** a package that asks for a restart is installed; the server is not
  restarted.
- **Running again** keeps Chocolatey and its packages.
- **Uninstall** removes Chocolatey's folder (`C:\ProgramData\chocolatey`) and its
  environment variables. Programs installed with it stay installed.
- Chocolatey and the community repository are not Microsoft's; packages are maintained by
  their authors. Only this recipe uses Chocolatey.

Not run on a real server yet.
