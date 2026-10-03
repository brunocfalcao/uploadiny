import { StatusBar } from 'expo-status-bar';
import { Image, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';

const steps = [
  ['1', 'Share a file'],
  ['2', 'Sign in once, then choose a project'],
  ['3', 'Your images arrive as one feedback chunk'],
] as const;

export default function App() {
  return (
    <SafeAreaProvider>
      <StatusBar style="light" />
      <SafeAreaView style={styles.screen}>
        <View style={styles.glow} />
        <ScrollView contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
          <View style={styles.brandRow}>
            <Image source={require('./assets/icon.png')} style={styles.icon} />
            <Text style={styles.brand}>Uploadiny</Text>
          </View>

          <View style={styles.hero}>
            <Text style={styles.eyebrow}>IPHONE → UPLOAD INBOX</Text>
            <Text style={styles.title}>Share it.{`\n`}Keep the context.</Text>
            <Text style={styles.subtitle}>
              Share images into a project. Draw, comment, and give your coding agent the whole feedback group.
            </Text>
          </View>

          <View style={styles.card}>
            {steps.map(([number, label], index) => (
              <View key={number} style={[styles.step, index > 0 && styles.stepBorder]}>
                <Text style={styles.stepNumber}>{number}</Text>
                <Text style={styles.stepLabel}>{label}</Text>
              </View>
            ))}
          </View>

          <Pressable accessibilityRole="link" onPress={() => Linking.openURL("https://uploadiny.com")} style={({ pressed }) => [styles.openWebsite, pressed && styles.openWebsitePressed]}><Text style={styles.openWebsiteText}>Open your workspace</Text></Pressable>
          <View style={styles.readyRow}>
            <View style={styles.readyDot} />
            <Text style={styles.readyText}>Ready in your Share Sheet</Text>
          </View>
        </ScrollView>
      </SafeAreaView>
    </SafeAreaProvider>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: '#070B18',
  },
  glow: {
    position: 'absolute',
    top: -170,
    right: -160,
    width: 430,
    height: 430,
    borderRadius: 215,
    backgroundColor: '#3348D8',
    opacity: 0.32,
  },
  content: {
    flexGrow: 1,
    paddingHorizontal: 28,
    paddingTop: 28,
    paddingBottom: 24,
  },
  brandRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  icon: {
    width: 42,
    height: 42,
    borderRadius: 12,
  },
  brand: {
    color: '#F7F8FF',
    fontSize: 20,
    fontWeight: '800',
    letterSpacing: -0.4,
  },
  hero: {
    marginTop: 48,
  },
  eyebrow: {
    color: '#91A4FF',
    fontSize: 12,
    fontWeight: '800',
    letterSpacing: 1.7,
  },
  title: {
    marginTop: 18,
    color: '#F7F8FF',
    fontSize: 48,
    lineHeight: 51,
    fontWeight: '900',
    letterSpacing: -2.2,
  },
  subtitle: {
    marginTop: 22,
    maxWidth: 340,
    color: '#A8B0CD',
    fontSize: 17,
    lineHeight: 25,
  },
  card: {
    marginTop: 36,
    borderWidth: 1,
    borderColor: '#242D50',
    borderRadius: 24,
    backgroundColor: '#10162A',
    overflow: 'hidden',
  },
  step: {
    minHeight: 68,
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 20,
    gap: 16,
  },
  stepBorder: {
    borderTopWidth: 1,
    borderTopColor: '#242D50',
  },
  stepNumber: {
    width: 26,
    color: '#7087FF',
    fontSize: 13,
    fontWeight: '900',
    letterSpacing: 1,
  },
  stepLabel: {
    flex: 1,
    color: '#E8EBF8',
    fontSize: 16,
    fontWeight: '700',
  },
  openWebsite: { marginTop: 28, minHeight: 56, padding: 18, borderRadius: 16, backgroundColor: "#4F63EA", borderWidth: 1, borderColor: "#7182FF", alignItems: "center" },
  openWebsitePressed: { backgroundColor: "#3C4FCB", transform: [{ scale: 0.98 }] },
  openWebsiteText: { color: "#FFFFFF", fontSize: 16, fontWeight: "700" },
  readyRow: {
    marginTop: 28,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 9,
  },
  readyDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: '#62E6A7',
  },
  readyText: {
    color: '#8790B0',
    fontSize: 13,
    fontWeight: '700',
  },
});
