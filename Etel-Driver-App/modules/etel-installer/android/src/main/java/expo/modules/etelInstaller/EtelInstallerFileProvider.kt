package expo.modules.etelInstaller

import androidx.core.content.FileProvider

// Its own subclass, so the manifest merger never sees a second plain FileProvider entry from another library.
class EtelInstallerFileProvider : FileProvider()
