package expo.modules.etelInstaller

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.SystemClock
import android.provider.Settings
import androidx.core.content.FileProvider
import expo.modules.kotlin.Promise
import expo.modules.kotlin.exception.Exceptions
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition
import java.io.File
import java.io.FileOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest

// Lets the ETEL app update itself: download the new APK from our own server with live progress, check its SHA-256, and hand it to
// Android's installer. Android still shows its own "Install" confirmation, and refuses an APK signed by a different key.
class EtelInstallerModule : Module() {
  private val context: Context
    get() = appContext.reactContext ?: throw Exceptions.ReactContextLost()

  @Volatile private var cancelled = false
  @Volatile private var running = false

  private class Cancelled : Exception()

  private fun apkFile(): File {
    val dir = File(context.cacheDir, "apk")
    dir.mkdirs()
    return File(dir, "etel-update.apk")
  }

  private fun hex(bytes: ByteArray): String {
    val sb = StringBuilder()
    for (b in bytes) sb.append(String.format("%02x", b))
    return sb.toString()
  }

  override fun definition() = ModuleDefinition {
    Name("EtelInstaller")
    Events("onProgress")

    // The build number of the installed app (the native version code), so "newer" is judged against the real binary.
    Function("versionCode") {
      val info = context.packageManager.getPackageInfo(context.packageName, 0)
      if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) info.longVersionCode.toDouble()
      else @Suppress("DEPRECATION") info.versionCode.toDouble()
    }

    Function("versionName") {
      context.packageManager.getPackageInfo(context.packageName, 0).versionName ?: ""
    }

    Function("canInstall") {
      Build.VERSION.SDK_INT < Build.VERSION_CODES.O || context.packageManager.canRequestPackageInstalls()
    }

    Function("openInstallSettings") {
      if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
        val intent = Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + context.packageName))
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        context.startActivity(intent)
      }
    }

    Function("cancel") { cancelled = true }

    AsyncFunction("download") { url: String, promise: Promise ->
      if (running) {
        promise.reject("E_BUSY", "A download is already running", null)
        return@AsyncFunction
      }
      running = true
      cancelled = false
      Thread {
        var conn: HttpURLConnection? = null
        try {
          val out = apkFile()
          out.parentFile?.listFiles()?.forEach { it.delete() }
          conn = URL(url).openConnection() as HttpURLConnection
          conn.connectTimeout = 15000
          conn.readTimeout = 20000
          conn.instanceFollowRedirects = true
          conn.connect()
          if (conn.responseCode !in 200..299) throw java.io.IOException("HTTP " + conn.responseCode)
          val total = conn.contentLengthLong
          val md = MessageDigest.getInstance("SHA-256")
          var received = 0L
          var lastEmit = 0L
          conn.inputStream.use { input ->
            FileOutputStream(out).use { fos ->
              val buf = ByteArray(64 * 1024)
              while (true) {
                if (cancelled) throw Cancelled()
                val n = input.read(buf)
                if (n < 0) break
                fos.write(buf, 0, n)
                md.update(buf, 0, n)
                received += n
                val now = SystemClock.elapsedRealtime()
                if (now - lastEmit >= 100) {
                  lastEmit = now
                  sendEvent("onProgress", mapOf("received" to received.toDouble(), "total" to total.toDouble()))
                }
              }
            }
          }
          sendEvent("onProgress", mapOf("received" to received.toDouble(), "total" to received.toDouble()))
          promise.resolve(mapOf("path" to out.absolutePath, "sha256" to hex(md.digest()), "bytes" to received.toDouble()))
        } catch (e: Cancelled) {
          apkFile().delete()
          promise.reject("E_CANCELLED", "Download cancelled", e)
        } catch (e: Exception) {
          apkFile().delete()
          promise.reject("E_DOWNLOAD", e.message ?: "Download failed", e)
        } finally {
          conn?.disconnect()
          running = false
        }
      }.start()
    }

    Function("install") { path: String ->
      val file = File(path)
      if (!file.exists()) throw java.io.FileNotFoundException("The update file is missing")
      val uri = FileProvider.getUriForFile(context, context.packageName + ".etelinstaller", file)
      val intent = Intent(Intent.ACTION_VIEW)
      intent.setDataAndType(uri, "application/vnd.android.package-archive")
      intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
      context.startActivity(intent)
    }
  }
}
