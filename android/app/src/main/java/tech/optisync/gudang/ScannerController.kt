package tech.optisync.gudang

import androidx.annotation.OptIn
import androidx.camera.core.Camera
import androidx.camera.core.CameraSelector
import androidx.camera.core.ExperimentalGetImage
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.core.content.ContextCompat
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors

/** Kamera + ML Kit: membaca barcode 1D (resi, SKU) dan QR. */
class ScannerController(
    private val activity: MainActivity,
    private val preview: PreviewView,
    private val onCode: (String, String) -> Unit,
) {
    var continuous = false

    private val executor: ExecutorService = Executors.newSingleThreadExecutor()
    private val scanner = BarcodeScanning.getClient(
        BarcodeScannerOptions.Builder().setBarcodeFormats(Barcode.FORMAT_ALL_FORMATS).build(),
    )
    private var provider: ProcessCameraProvider? = null
    private var camera: Camera? = null

    @Volatile
    private var active = false
    private var lastCode: String? = null
    private var lastAt = 0L

    fun start() {
        active = true
        lastCode = null
        val future = ProcessCameraProvider.getInstance(activity)
        future.addListener({
            if (!active) return@addListener
            val cameraProvider = future.get()
            provider = cameraProvider

            val previewUseCase = Preview.Builder().build()
            previewUseCase.setSurfaceProvider(preview.surfaceProvider)

            val analysis = ImageAnalysis.Builder()
                .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                .build()
            analysis.setAnalyzer(executor) { proxy -> analyze(proxy) }

            try {
                cameraProvider.unbindAll()
                camera = cameraProvider.bindToLifecycle(activity, CameraSelector.DEFAULT_BACK_CAMERA, previewUseCase, analysis)
            } catch (e: Exception) {
                activity.toast("Kamera tidak bisa dibuka: ${e.message}")
            }
        }, ContextCompat.getMainExecutor(activity))
    }

    fun stop() {
        active = false
        camera?.cameraControl?.enableTorch(false)
        provider?.unbindAll()
        camera = null
    }

    fun torch(on: Boolean) {
        camera?.cameraControl?.enableTorch(on)
    }

    @OptIn(markerClass = [ExperimentalGetImage::class])
    private fun analyze(proxy: ImageProxy) {
        val media = proxy.image
        if (media == null || !active) {
            proxy.close()
            return
        }
        val image = InputImage.fromMediaImage(media, proxy.imageInfo.rotationDegrees)
        scanner.process(image)
            .addOnSuccessListener { codes ->
                val hit = codes.firstOrNull { !it.rawValue.isNullOrBlank() }
                if (hit != null) deliver(hit.rawValue!!.trim(), formatName(hit.format))
            }
            .addOnCompleteListener { proxy.close() }
    }

    private fun deliver(code: String, format: String) {
        if (!active) return
        val now = System.currentTimeMillis()
        // Kode yang sama tidak dibaca dua kali selama masih di depan kamera.
        if (code == lastCode && now - lastAt < 2500) return
        if (now - lastAt < 700) return
        lastCode = code
        lastAt = now
        if (!continuous) active = false
        onCode(code, format)
    }

    private fun formatName(format: Int): String = when (format) {
        Barcode.FORMAT_QR_CODE -> "qr"
        Barcode.FORMAT_CODE_128 -> "code128"
        Barcode.FORMAT_CODE_39 -> "code39"
        Barcode.FORMAT_EAN_13 -> "ean13"
        Barcode.FORMAT_EAN_8 -> "ean8"
        Barcode.FORMAT_UPC_A -> "upca"
        Barcode.FORMAT_DATA_MATRIX -> "datamatrix"
        else -> "other"
    }
}
