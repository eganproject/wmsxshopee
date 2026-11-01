package tech.optisync.gudang

import android.annotation.SuppressLint
import android.bluetooth.BluetoothManager
import android.bluetooth.BluetoothSocket
import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.util.UUID

/** Kirim data mentah ke printer thermal lewat Bluetooth klasik (profil SPP). */
object BluetoothPrinter {
    private val SPP: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")

    @SuppressLint("MissingPermission")
    fun paired(context: Context): JSONArray {
        val adapter = context.getSystemService(BluetoothManager::class.java)?.adapter
            ?: throw IllegalStateException("HP ini tidak punya Bluetooth.")
        if (!adapter.isEnabled) throw IllegalStateException("Bluetooth mati. Nyalakan Bluetooth dulu.")
        val list = JSONArray()
        adapter.bondedDevices.sortedBy { it.name ?: "" }.forEach { device ->
            list.put(JSONObject().put("name", device.name ?: device.address).put("address", device.address))
        }
        return list
    }

    @SuppressLint("MissingPermission")
    fun send(context: Context, address: String, data: ByteArray) {
        val adapter = context.getSystemService(BluetoothManager::class.java)?.adapter
            ?: throw IllegalStateException("HP ini tidak punya Bluetooth.")
        if (!adapter.isEnabled) throw IllegalStateException("Bluetooth mati. Nyalakan Bluetooth dulu.")
        adapter.cancelDiscovery()
        val device = adapter.getRemoteDevice(address)

        var socket: BluetoothSocket? = null
        try {
            val secure = device.createRfcommSocketToServiceRecord(SPP)
            socket = try {
                secure.connect()
                secure
            } catch (e: IOException) {
                secure.closeQuietly()
                // Sebagian printer murah hanya mau koneksi tanpa enkripsi.
                device.createInsecureRfcommSocketToServiceRecord(SPP).also { it.connect() }
            }
            val out = socket.outputStream
            var offset = 0
            while (offset < data.size) {
                val size = minOf(2048, data.size - offset)
                out.write(data, offset, size)
                out.flush()
                offset += size
                Thread.sleep(15)
            }
            // Beri waktu printer menerima sisa data sebelum koneksi ditutup.
            Thread.sleep(600)
        } catch (e: IOException) {
            throw IOException("Printer tidak terhubung. Pastikan printer menyala dan dekat HP.", e)
        } finally {
            socket?.closeQuietly()
        }
    }

    private fun BluetoothSocket.closeQuietly() {
        try {
            close()
        } catch (_: IOException) {
        }
    }
}
