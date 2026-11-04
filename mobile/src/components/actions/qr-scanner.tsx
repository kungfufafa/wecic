import { CameraView, useCameraPermissions, type BarcodeScanningResult } from 'expo-camera';
import { useRef, useState } from 'react';
import { Linking, Modal, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/components/button';
import { IconButton } from '@/components/icon-button';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type QrScannerProps = {
  visible: boolean;
  /** The text of the first QR seen; the scanner closes itself after it. */
  onScanned: (text: string) => void;
  onClose: () => void;
};

const FRAME = 260;

/**
 * The camera, full screen, looking for one QR: the attendance code the
 * lecturer shows. Asks for the camera first; a refusal says how to allow
 * it in Settings.
 */
export function QrScanner({ visible, onScanned, onClose }: QrScannerProps) {
  const theme = useTheme();
  // A Modal is a window of its own, where SafeAreaView reads no insets: the app's are applied by hand.
  const insets = useSafeAreaInsets();
  const [permission, requestPermission] = useCameraPermissions();
  const [torch, setTorch] = useState(false);
  const handled = useRef(false);

  function handleScan(result: BarcodeScanningResult) {
    if (handled.current || typeof result.data !== 'string' || result.data.trim() === '') {
      return;
    }

    handled.current = true;
    setTorch(false);
    onScanned(result.data);
  }

  function close() {
    setTorch(false);
    onClose();
  }

  const granted = permission?.granted === true;
  const canAskAgain = permission?.canAskAgain !== false;

  return (
    <Modal
      visible={visible}
      animationType="slide"
      presentationStyle="fullScreen"
      onRequestClose={close}
      onShow={() => {
        handled.current = false;
      }}>
      <View style={[styles.root, { backgroundColor: theme.background }]}>
        {visible && granted ? (
          <CameraView
            style={StyleSheet.absoluteFill}
            facing="back"
            enableTorch={torch}
            barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
            onBarcodeScanned={handleScan}
          />
        ) : null}

        <View style={[styles.overlay, { paddingTop: insets.top, paddingBottom: insets.bottom }]}>
          <View style={styles.top}>
            <IconButton icon="close" label="Tutup pemindai" onPress={close} />
            {granted ? <IconButton icon="bolt" label={torch ? 'Matikan senter' : 'Nyalakan senter'} onPress={() => setTorch((current) => !current)} /> : null}
          </View>

          {granted ? (
            <View style={styles.center} pointerEvents="none">
              <View style={[styles.frame, { borderColor: theme.onColor }]} />
              <View style={[styles.hint, { backgroundColor: 'rgba(0, 0, 0, 0.6)' }]}>
                <ThemedText type="smallBold" style={{ color: theme.onColor }}>
                  Arahkan kamera ke QR absensi
                </ThemedText>
                <ThemedText type="caption" style={{ color: 'rgba(255, 255, 255, 0.75)' }}>
                  Kode terbaca otomatis lalu langsung dikirim.
                </ThemedText>
              </View>
            </View>
          ) : (
            <View style={styles.permission}>
              <ThemedText type="heading" style={styles.centered}>
                Izinkan kamera
              </ThemedText>
              <ThemedText type="small" themeColor="textSecondary" style={styles.centered}>
                {permission === null
                  ? 'Memeriksa izin kamera…'
                  : canAskAgain
                    ? 'WeCIC butuh kamera untuk memindai QR absensi dari dosen. Kamera hanya dipakai selama pemindai terbuka.'
                    : 'Akses kamera ditolak. Izinkan kamera untuk WeCIC di Pengaturan, atau ketik kodenya saja.'}
              </ThemedText>
              {permission !== null ? (
                canAskAgain ? (
                  <Button label="Izinkan kamera" icon="qr" onPress={() => void requestPermission()} />
                ) : (
                  <Button label="Buka Pengaturan" onPress={() => void Linking.openSettings()} />
                )
              ) : null}
            </View>
          )}
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
  },
  overlay: {
    flex: 1,
  },
  top: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingHorizontal: Spacing.three,
    paddingTop: Spacing.two,
  },
  center: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: Spacing.four,
    paddingBottom: Spacing.six,
  },
  frame: {
    width: FRAME,
    height: FRAME,
    borderWidth: 3,
    borderRadius: Radius.section,
  },
  hint: {
    alignItems: 'center',
    gap: Spacing.half,
    paddingHorizontal: Spacing.four,
    paddingVertical: Spacing.two + Spacing.one,
    borderRadius: Radius.pill,
  },
  permission: {
    flex: 1,
    justifyContent: 'center',
    gap: Spacing.three,
    paddingHorizontal: Spacing.four,
  },
  centered: {
    textAlign: 'center',
  },
});
