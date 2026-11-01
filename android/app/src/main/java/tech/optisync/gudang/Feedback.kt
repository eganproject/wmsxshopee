package tech.optisync.gudang

import android.content.Context
import android.media.AudioManager
import android.media.ToneGenerator
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.os.Build

/** Bunyi & getar sebagai tanda hasil scan, supaya petugas tidak perlu melihat layar. */
object Feedback {
    private var tone: ToneGenerator? = null

    fun play(context: Context, kind: String) {
        try {
            val generator = tone ?: ToneGenerator(AudioManager.STREAM_MUSIC, 90).also { tone = it }
            when (kind) {
                "ok" -> generator.startTone(ToneGenerator.TONE_PROP_ACK, 150)
                "error" -> generator.startTone(ToneGenerator.TONE_SUP_ERROR, 450)
                "warn" -> generator.startTone(ToneGenerator.TONE_PROP_NACK, 300)
                else -> generator.startTone(ToneGenerator.TONE_PROP_BEEP, 90)
            }
        } catch (e: RuntimeException) {
            tone = null
        }

        val pattern = when (kind) {
            "error" -> longArrayOf(0, 120, 80, 120, 80, 220)
            "warn" -> longArrayOf(0, 90, 70, 90)
            "ok" -> longArrayOf(0, 40)
            else -> longArrayOf(0, 25)
        }
        vibrator(context)?.vibrate(VibrationEffect.createWaveform(pattern, -1))
    }

    private fun vibrator(context: Context): Vibrator? =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            (context.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as? VibratorManager)?.defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
        }
}
