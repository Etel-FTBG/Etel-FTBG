package expo.modules.etelOverlay

import android.animation.ObjectAnimator
import android.animation.ValueAnimator
import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.graphics.PixelFormat
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import android.view.animation.LinearInterpolator
import android.widget.FrameLayout
import android.widget.ImageView
import expo.modules.kotlin.exception.Exceptions
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition

// The golden blinking ETEL logo shown at the top-left over other apps while the user is still online.
// It needs the "Display over other apps" permission. Tapping it brings the ETEL app back to the front.
class EtelOverlayModule : Module() {
  private val context: Context
    get() = appContext.reactContext ?: throw Exceptions.ReactContextLost()

  private val main = Handler(Looper.getMainLooper())
  private var badge: View? = null
  private var blink: ObjectAnimator? = null

  private fun canDraw(): Boolean =
    Build.VERSION.SDK_INT < Build.VERSION_CODES.M || Settings.canDrawOverlays(context)

  private fun dp(v: Int): Int = (v * context.resources.displayMetrics.density).toInt()

  private fun buildBadge(): View {
    val size = dp(46)
    val frame = FrameLayout(context)
    val ring = GradientDrawable().apply {
      shape = GradientDrawable.OVAL
      setColor(Color.parseColor("#E60A0703"))
      setStroke(dp(2), Color.parseColor("#FFD4AF37"))
    }
    frame.background = ring
    frame.elevation = dp(6).toFloat()
    val logo = ImageView(context)
    try {
      logo.setImageDrawable(context.packageManager.getApplicationIcon(context.packageName))
    } catch (e: Exception) {
      // a plain golden dot is still a valid "online" signal
    }
    logo.scaleType = ImageView.ScaleType.FIT_CENTER
    val pad = dp(7)
    logo.setPadding(pad, pad, pad, pad)
    frame.addView(logo, FrameLayout.LayoutParams(size, size))
    frame.setOnClickListener { bringAppToFront() }
    return frame
  }

  private fun bringAppToFront() {
    val launch = context.packageManager.getLaunchIntentForPackage(context.packageName) ?: return
    launch.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT or Intent.FLAG_ACTIVITY_SINGLE_TOP)
    context.startActivity(launch)
  }

  private fun show() {
    if (badge != null || !canDraw()) return
    val wm = context.getSystemService(Context.WINDOW_SERVICE) as WindowManager
    val type = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O)
      WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
    else
      @Suppress("DEPRECATION") WindowManager.LayoutParams.TYPE_PHONE
    val size = dp(46)
    val params = WindowManager.LayoutParams(
      size, size, type,
      WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS,
      PixelFormat.TRANSLUCENT
    )
    params.gravity = Gravity.TOP or Gravity.START
    params.x = dp(8)
    params.y = dp(32)
    val v = buildBadge()
    try {
      wm.addView(v, params)
    } catch (e: Exception) {
      return
    }
    badge = v
    // the golden light: a slow fade in and out
    blink = ObjectAnimator.ofFloat(v, "alpha", 1f, 0.3f).apply {
      duration = 900
      repeatMode = ValueAnimator.REVERSE
      repeatCount = ValueAnimator.INFINITE
      interpolator = LinearInterpolator()
      start()
    }
  }

  private fun hide() {
    blink?.cancel()
    blink = null
    val v = badge ?: return
    badge = null
    try {
      (context.getSystemService(Context.WINDOW_SERVICE) as WindowManager).removeView(v)
    } catch (e: Exception) {
      // already gone
    }
  }

  override fun definition() = ModuleDefinition {
    Name("EtelOverlay")

    Function("canDrawOverlays") { canDraw() }

    Function("requestPermission") {
      if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && !Settings.canDrawOverlays(context)) {
        val intent = Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:" + context.packageName))
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        context.startActivity(intent)
      }
    }

    Function("show") { main.post { show() } }
    Function("hide") { main.post { hide() } }
    Function("isShowing") { badge != null }

    OnDestroy { main.post { hide() } }
  }
}
