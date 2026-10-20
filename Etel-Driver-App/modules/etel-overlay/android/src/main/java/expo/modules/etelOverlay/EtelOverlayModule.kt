package expo.modules.etelOverlay

import android.animation.ObjectAnimator
import android.animation.ValueAnimator
import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.graphics.PixelFormat
import android.graphics.Typeface
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
import android.widget.LinearLayout
import android.widget.TextView
import expo.modules.kotlin.exception.Exceptions
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition

// The golden blinking ETEL logo shown at the top-left over other apps while the user is still online or has something
// going on. A red bubble on the logo shows the total of live trips, new requests, new orders and deliveries in progress;
// small pills beside it name each one. It blinks faster while there is something to attend to.
// It needs the "Display over other apps" permission. Tapping it brings the ETEL app back to the front.
class EtelOverlayModule : Module() {
  private val context: Context
    get() = appContext.reactContext ?: throw Exceptions.ReactContextLost()

  private val main = Handler(Looper.getMainLooper())
  private var badge: View? = null
  private var blink: ObjectAnimator? = null
  private var logoFrame: View? = null
  private var bubble: TextView? = null
  private var pillBox: LinearLayout? = null
  // trips, new requests, new orders, deliveries in progress
  private var counts = intArrayOf(0, 0, 0, 0)

  private fun canDraw(): Boolean =
    Build.VERSION.SDK_INT < Build.VERSION_CODES.M || Settings.canDrawOverlays(context)

  private fun dp(v: Int): Int = (v * context.resources.displayMetrics.density).toInt()

  private fun buildBadge(): View {
    val size = dp(46)
    val root = LinearLayout(context)
    root.orientation = LinearLayout.HORIZONTAL
    root.gravity = Gravity.CENTER_VERTICAL

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

    val dot = TextView(context)
    dot.setTextColor(Color.WHITE)
    dot.textSize = 10f
    dot.typeface = Typeface.DEFAULT_BOLD
    dot.gravity = Gravity.CENTER
    dot.background = GradientDrawable().apply {
      shape = GradientDrawable.OVAL
      setColor(Color.parseColor("#FFE5484D"))
    }
    dot.visibility = View.GONE
    frame.addView(dot, FrameLayout.LayoutParams(dp(20), dp(20), Gravity.TOP or Gravity.END))

    val box = LinearLayout(context)
    box.orientation = LinearLayout.VERTICAL

    root.addView(frame, LinearLayout.LayoutParams(size, size))
    val boxLp = LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT)
    boxLp.leftMargin = dp(6)
    root.addView(box, boxLp)
    root.setOnClickListener { bringAppToFront() }

    logoFrame = frame
    bubble = dot
    pillBox = box
    return root
  }

  private fun pill(label: String): TextView {
    val t = TextView(context)
    t.text = label
    t.setTextColor(Color.parseColor("#FFD4AF37"))
    t.textSize = 11f
    t.typeface = Typeface.DEFAULT_BOLD
    t.setPadding(dp(8), dp(3), dp(8), dp(3))
    t.background = GradientDrawable().apply {
      cornerRadius = dp(10).toFloat()
      setColor(Color.parseColor("#E60A0703"))
      setStroke(dp(1), Color.parseColor("#99D4AF37"))
    }
    val lp = LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT)
    lp.topMargin = dp(2)
    t.layoutParams = lp
    return t
  }

  // The golden light: a slow fade while merely online, a quicker pulse while there is something to attend to.
  private fun startBlink(fast: Boolean) {
    blink?.cancel()
    val target = logoFrame ?: return
    blink = ObjectAnimator.ofFloat(target, "alpha", 1f, if (fast) 0.15f else 0.3f).apply {
      duration = if (fast) 450L else 900L
      repeatMode = ValueAnimator.REVERSE
      repeatCount = ValueAnimator.INFINITE
      interpolator = LinearInterpolator()
      start()
    }
  }

  private fun applyCounts() {
    val dot = bubble ?: return
    val box = pillBox ?: return
    var total = 0
    for (c in counts) total += c
    if (total > 0) {
      dot.text = if (total > 99) "99+" else total.toString()
      dot.visibility = View.VISIBLE
    } else {
      dot.visibility = View.GONE
    }
    box.removeAllViews()
    val labels = arrayOf("Trip", "Request", "Order", "Delivery")
    for (i in counts.indices) {
      if (counts[i] > 0) box.addView(pill(labels[i] + " " + counts[i]))
    }
    startBlink(total > 0)
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
    val params = WindowManager.LayoutParams(
      WindowManager.LayoutParams.WRAP_CONTENT, WindowManager.LayoutParams.WRAP_CONTENT, type,
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
    applyCounts()
  }

  private fun hide() {
    blink?.cancel()
    blink = null
    logoFrame = null
    bubble = null
    pillBox = null
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
    Function("update") { trips: Int, requests: Int, orders: Int, deliveries: Int ->
      counts = intArrayOf(trips, requests, orders, deliveries)
      main.post { applyCounts() }
    }
    Function("hide") { main.post { hide() } }
    Function("isShowing") { badge != null }

    OnDestroy { main.post { hide() } }
  }
}
