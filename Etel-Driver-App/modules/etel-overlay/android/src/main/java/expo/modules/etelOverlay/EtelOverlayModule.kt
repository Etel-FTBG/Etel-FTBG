package expo.modules.etelOverlay

import android.animation.Animator
import android.animation.AnimatorListenerAdapter
import android.animation.AnimatorSet
import android.animation.ObjectAnimator
import android.animation.ValueAnimator
import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.graphics.PixelFormat
import android.graphics.Typeface
import android.graphics.drawable.Drawable
import android.graphics.drawable.GradientDrawable
import android.graphics.drawable.LayerDrawable
import android.media.Ringtone
import android.media.RingtoneManager
import android.os.PowerManager
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.VibrationEffect
import android.os.Vibrator
import android.provider.Settings
import android.view.Gravity
import android.view.MotionEvent
import android.view.ViewConfiguration
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
// It needs the "Display over other apps" permission. Tapping it brings the ETEL app back to the front; dragging it moves it
// anywhere on the screen, and the spot is remembered. The pills open towards the middle of the screen.
class EtelOverlayModule : Module() {
  private val context: Context
    get() = appContext.reactContext ?: throw Exceptions.ReactContextLost()

  private val main = Handler(Looper.getMainLooper())
  private var badge: View? = null
  private var blink: ObjectAnimator? = null
  private var logoFrame: View? = null
  private var bubble: TextView? = null
  private var pillBox: LinearLayout? = null
  private var params: WindowManager.LayoutParams? = null
  private var rightSide = false
  // trips, new requests, new orders, deliveries in progress
  private var counts = intArrayOf(0, 0, 0, 0)
  // The ring that spreads out from the logo while there is something to attend to, and the pulse of the logo itself.
  private var halo: View? = null
  private var haloAnim: AnimatorSet? = null
  private var burst: AnimatorSet? = null
  // Sound and vibration for a NEW request or order (the owner can switch them off in Admin > ETEL app).
  private var soundOn = true
  private var vibrateOn = true
  // requests + orders at the last update; -1 = nothing shown yet, so the first numbers after the badge appears do not make a noise
  private var lastAttention = -1
  private var labels = arrayOf("Trip", "Request", "Order", "Delivery")
  // The ring of a new request: it keeps ringing (like a call) until the driver taps the light, the request is gone, or 15 seconds pass.
  private var ringtone: Ringtone? = null
  private val stopRingLater = Runnable { stopRing() }

  private fun canDraw(): Boolean =
    Build.VERSION.SDK_INT < Build.VERSION_CODES.M || Settings.canDrawOverlays(context)

  private fun dp(v: Int): Int = (v * context.resources.displayMetrics.density).toInt()

  private val prefs by lazy { context.getSharedPreferences("etel_overlay", Context.MODE_PRIVATE) }
  private val windowManager: WindowManager
    get() = context.getSystemService(Context.WINDOW_SERVICE) as WindowManager

  // Keeps the badge fully on screen, whatever its current width (the pills come and go).
  private fun clamp(v: View, lp: WindowManager.LayoutParams) {
    val dm = context.resources.displayMetrics
    val w = if (v.width > 0) v.width else dp(60)
    val h = if (v.height > 0) v.height else dp(60)
    lp.x = lp.x.coerceIn(0, maxOf(0, dm.widthPixels - w))
    lp.y = lp.y.coerceIn(0, maxOf(0, dm.heightPixels - h))
  }

  // The pills sit on the side of the logo that faces the middle of the screen, so they never run off the edge.
  private fun layoutSide(root: LinearLayout, right: Boolean) {
    val frame = logoFrame ?: return
    val box = pillBox ?: return
    root.removeAllViews()
    val size = dp(46)
    val boxLp = LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT)
    if (right) {
      boxLp.rightMargin = dp(6)
      box.gravity = Gravity.END
      root.addView(box, boxLp)
      root.addView(frame, LinearLayout.LayoutParams(size, size))
    } else {
      boxLp.leftMargin = dp(6)
      box.gravity = Gravity.START
      root.addView(frame, LinearLayout.LayoutParams(size, size))
      root.addView(box, boxLp)
    }
    rightSide = right
  }

  private fun savePosition() {
    val lp = params ?: return
    prefs.edit().putInt("x", lp.x).putInt("y", lp.y).apply()
  }

  private fun attachDrag(root: LinearLayout) {
    val slop = ViewConfiguration.get(context).scaledTouchSlop
    var downX = 0f
    var downY = 0f
    var startX = 0
    var startY = 0
    var dragging = false
    root.setOnTouchListener { v, e ->
      val lp = params ?: return@setOnTouchListener false
      when (e.action) {
        MotionEvent.ACTION_DOWN -> {
          downX = e.rawX; downY = e.rawY; startX = lp.x; startY = lp.y; dragging = false
          true
        }
        MotionEvent.ACTION_MOVE -> {
          val dx = e.rawX - downX
          val dy = e.rawY - downY
          if (!dragging && (Math.abs(dx) > slop || Math.abs(dy) > slop)) dragging = true
          if (dragging) {
            lp.x = startX + dx.toInt()
            lp.y = startY + dy.toInt()
            clamp(v, lp)
            try { windowManager.updateViewLayout(v, lp) } catch (ex: Exception) { }
          }
          true
        }
        MotionEvent.ACTION_UP -> {
          if (dragging) {
            savePosition()
            val dm = context.resources.displayMetrics
            val right = lp.x + v.width / 2 > dm.widthPixels / 2
            if (right != rightSide) {
              layoutSide(root, right)
              v.post {
                clamp(v, lp)
                try { windowManager.updateViewLayout(v, lp) } catch (ex: Exception) { }
                savePosition()
              }
            }
          } else {
            stopRing()
            bringAppToFront()
          }
          true
        }
        else -> false
      }
    }
  }

  private fun buildBadge(): View {
    val size = dp(46)
    val root = LinearLayout(context)
    root.orientation = LinearLayout.HORIZONTAL
    root.gravity = Gravity.CENTER_VERTICAL
    root.setPadding(dp(10), dp(10), dp(10), dp(10))   // room for the ring that spreads out from the logo
    root.clipChildren = false
    root.clipToPadding = false

    val frame = FrameLayout(context)
    // A white outer edge (so the badge stands out on a light wallpaper), a solid dark disc and a thick gold ring.
    val edge = GradientDrawable().apply {
      shape = GradientDrawable.OVAL
      setColor(Color.parseColor("#FFFFFFFF"))
    }
    val disc = GradientDrawable().apply {
      shape = GradientDrawable.OVAL
      setColor(Color.parseColor("#FF0A0703"))
      setStroke(dp(3), Color.parseColor("#FFF2CC6B"))
    }
    val ring = LayerDrawable(arrayOf<Drawable>(edge, disc))
    ring.setLayerInset(1, dp(2), dp(2), dp(2), dp(2))
    frame.background = ring
    frame.elevation = dp(8).toFloat()
    frame.clipChildren = false
    val haloView = View(context)
    haloView.background = GradientDrawable().apply {
      shape = GradientDrawable.OVAL
      setColor(Color.TRANSPARENT)
      setStroke(dp(3), Color.parseColor("#FFF2CC6B"))
    }
    haloView.alpha = 0f
    frame.addView(haloView, FrameLayout.LayoutParams(size, size))
    halo = haloView
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

    logoFrame = frame
    bubble = dot
    pillBox = box
    layoutSide(root, rightSide)
    attachDrag(root)
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

  // The golden light. While merely online it breathes gently but stays clearly visible (never fainter than 80%). While there is something to
  // attend to it pulses larger and a gold ring spreads out from the logo.
  private fun startBlink(fast: Boolean) {
    blink?.cancel()
    haloAnim?.cancel()
    val target = logoFrame ?: return
    blink = ObjectAnimator.ofFloat(target, "alpha", 1f, if (fast) 0.9f else 0.8f).apply {
      duration = if (fast) 500L else 1400L
      repeatMode = ValueAnimator.REVERSE
      repeatCount = ValueAnimator.INFINITE
      interpolator = LinearInterpolator()
      start()
    }
    val h = halo ?: return
    if (!fast) {
      h.alpha = 0f
      target.scaleX = 1f
      target.scaleY = 1f
      return
    }
    val spreadX = ObjectAnimator.ofFloat(h, "scaleX", 1f, 1.7f)
    val spreadY = ObjectAnimator.ofFloat(h, "scaleY", 1f, 1.7f)
    val fade = ObjectAnimator.ofFloat(h, "alpha", 0.85f, 0f)
    val grow = ObjectAnimator.ofFloat(target, "scaleX", 1f, 1.12f)
    val growY = ObjectAnimator.ofFloat(target, "scaleY", 1f, 1.12f)
    for (a in arrayOf(spreadX, spreadY, fade, grow, growY)) {
      a.duration = 1100L
      a.repeatCount = ValueAnimator.INFINITE
    }
    for (a in arrayOf(grow, growY)) a.repeatMode = ValueAnimator.REVERSE
    haloAnim = AnimatorSet().apply {
      playTogether(spreadX, spreadY, fade, grow, growY)
      start()
    }
  }

  // A new request or order just arrived: three quick bumps of the logo, a short vibration and the phone's notification sound.
  private fun attentionBurst() {
    val target = logoFrame
    if (target != null) {
      val older = burst
      burst = null            // so the older burst's end does not restart the pulse under this one
      older?.cancel()
      val sx = ObjectAnimator.ofFloat(target, "scaleX", 1f, 1.4f, 1f)
      val sy = ObjectAnimator.ofFloat(target, "scaleY", 1f, 1.4f, 1f)
      for (a in arrayOf(sx, sy)) {
        a.duration = 350L
        a.repeatCount = 2
      }
      haloAnim?.cancel()
      halo?.alpha = 0f
      val set = AnimatorSet()
      burst = set
      set.playTogether(sx, sy)
      set.addListener(object : AnimatorListenerAdapter() {
        override fun onAnimationEnd(animation: Animator) {
          if (burst === set && badge != null) startBlink(counts.sum() > 0)
        }
      })
      set.start()
    }
    if (vibrateOn) {
      try {
        val vib = context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
        val pattern = longArrayOf(0, 220, 120, 220)
        if (vib != null) {
          if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            vib.vibrate(VibrationEffect.createWaveform(pattern, -1))
          } else {
            @Suppress("DEPRECATION") vib.vibrate(pattern, -1)
          }
        }
      } catch (e: Exception) {
        // no vibrator, or the permission was refused: the light still pulses
      }
    }
    if (soundOn) startRing()
  }

  private fun startRing() {
    stopRing()
    try {
      val uri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE)
      val r = RingtoneManager.getRingtone(context, uri) ?: return
      if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) r.isLooping = true
      r.play()
      ringtone = r
      main.postDelayed(stopRingLater, 15000L)
    } catch (e: Exception) {
      // silent phone or no default ringtone
    }
  }

  private fun stopRing() {
    main.removeCallbacks(stopRingLater)
    val r = ringtone
    ringtone = null
    try { r?.stop() } catch (e: Exception) { }
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
    for (i in counts.indices) {
      if (counts[i] > 0) box.addView(pill(labels[i] + " " + counts[i]))
    }
    // A request or an order that was not there before = something new to attend to.
    val attention = counts[1] + counts[2]
    val fresh = lastAttention >= 0 && attention > lastAttention
    lastAttention = attention
    if (attention == 0) stopRing()          // the request was taken or cancelled: stop ringing
    if (fresh) attentionBurst() else if (burst?.isRunning != true) startBlink(total > 0)
    val v = badge
    val lp = params
    if (v != null && lp != null) {
      v.post {
        clamp(v, lp)
        try { windowManager.updateViewLayout(v, lp) } catch (e: Exception) { }
      }
    }
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
    params.x = prefs.getInt("x", dp(8))
    params.y = prefs.getInt("y", dp(32))
    rightSide = params.x + dp(30) > context.resources.displayMetrics.widthPixels / 2
    val v = buildBadge()
    try {
      wm.addView(v, params)
    } catch (e: Exception) {
      return
    }
    badge = v
    this.params = params
    v.post {
      clamp(v, params)
      try { wm.updateViewLayout(v, params) } catch (e: Exception) { }
    }
    applyCounts()
  }

  private fun hide() {
    stopRing()
    blink?.cancel()
    blink = null
    haloAnim?.cancel()
    haloAnim = null
    val oldBurst = burst
    burst = null
    oldBurst?.cancel()
    halo = null
    lastAttention = -1
    logoFrame = null
    bubble = null
    pillBox = null
    params = null
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
    // Newer than the first build: the owner's switch for sound and vibration, and the words on the pills.
    Function("setAlerts") { sound: Boolean, vibrate: Boolean ->
      soundOn = sound
      vibrateOn = vibrate
      true
    }
    // Rings and pulses the light now (a new request arrived while the light was not on screen).
    Function("ring") {
      main.post { if (badge != null) attentionBurst() }
      true
    }
    // Android battery saver: ask the phone to leave this app alone in the background.
    Function("isIgnoringBatteryOptimizations") {
      if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) true
      else (context.getSystemService(Context.POWER_SERVICE) as PowerManager).isIgnoringBatteryOptimizations(context.packageName)
    }
    // Google Play restricts the permission that shows the one-tap "allow" dialog, so this opens the phone's own battery list instead.
    // The person finds ETEL there and picks "Don't optimise" / "Unrestricted". isIgnoringBatteryOptimizations needs no permission.
    Function("requestIgnoreBatteryOptimizations") {
      try {
        val intent = Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        context.startActivity(intent)
      } catch (e: Exception) {
        try {
          val fallback = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + context.packageName))
          fallback.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
          context.startActivity(fallback)
        } catch (e2: Exception) {
          // no such screen on this phone
        }
      }
      true
    }
    Function("setLabels") { trip: String, request: String, order: String, delivery: String ->
      labels = arrayOf(trip, request, order, delivery)
      main.post { applyCounts() }
    }
    Function("isShowing") { badge != null }

    OnDestroy { main.post { hide() } }
  }
}
