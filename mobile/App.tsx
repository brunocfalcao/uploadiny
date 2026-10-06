import { StatusBar } from 'expo-status-bar';
import { Image, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';

const steps = [
  ['1', 'Share a file'],
  ['2', 'Sign in once, then choose a project'],
  ['3', 'Your screenshots and recordings arrive as one feedback chunk'],
] as const;

export default function App() {
  return (
    <SafeAreaProvider>
      <StatusBar style="dark" />
      <SafeAreaView style={styles.screen}>
        <View style={styles.glow} />
        <ScrollView contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
          <View style={styles.brandRow}>
            <Image source={require('./assets/icon.png')} style={styles.icon} />
            <Text style={styles.brand}>Uploadiny</Text>
          </View>

          <View style={styles.hero}>
            <Text style={styles.eyebrow}>Share Sheet → your workspace</Text>
            <Text style={styles.title}>Share it.{`\n`}Keep the context.</Text>
            <Text style={styles.subtitle}>
              Share screenshots and recordings into a project. Draw, comment, and give your coding agent the whole feedback group.
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
  screen: { flex: 1, backgroundColor: '#EEF0F4' },
  glow: { position: 'absolute', top: -180, right: -150, width: 420, height: 420, borderRadius: 210, backgroundColor: '#DCD9FB', opacity: 0.7 },
  content: { flexGrow: 1, paddingHorizontal: 24, paddingTop: 24, paddingBottom: 24 },
  brandRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  icon: { width: 40, height: 40, borderRadius: 12 },
  brand: { color: '#0E1525', fontSize: 20, fontWeight: '800', letterSpacing: -0.5 },
  hero: { marginTop: 44 },
  eyebrow: { alignSelf: 'flex-start', overflow: 'hidden', paddingHorizontal: 12, paddingVertical: 6, borderRadius: 999, backgroundColor: '#FFFFFF', color: '#4338CA', fontSize: 12, fontWeight: '700', letterSpacing: 0.4 },
  title: { marginTop: 18, color: '#0E1525', fontSize: 46, lineHeight: 50, fontWeight: '800', letterSpacing: -2 },
  subtitle: { marginTop: 18, maxWidth: 340, color: '#4A5568', fontSize: 17, lineHeight: 25 },
  card: { marginTop: 32, borderRadius: 24, backgroundColor: '#FFFFFF', overflow: 'hidden', shadowColor: '#0E1525', shadowOpacity: 0.1, shadowRadius: 24, shadowOffset: { width: 0, height: 10 } },
  step: { minHeight: 68, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 18, gap: 14 },
  stepBorder: { borderTopWidth: 1, borderTopColor: '#E3E6EE' },
  stepNumber: { width: 30, height: 30, borderRadius: 15, overflow: 'hidden', textAlign: 'center', lineHeight: 30, backgroundColor: '#EEEDFD', color: '#4338CA', fontSize: 13, fontWeight: '800' },
  stepLabel: { flex: 1, color: '#0E1525', fontSize: 16, fontWeight: '600', lineHeight: 22 },
  openWebsite: { marginTop: 28, minHeight: 56, padding: 18, borderRadius: 999, backgroundColor: '#5146E5', alignItems: 'center', shadowColor: '#5146E5', shadowOpacity: 0.45, shadowRadius: 18, shadowOffset: { width: 0, height: 10 } },
  openWebsitePressed: { backgroundColor: '#4338CA', transform: [{ scale: 0.97 }] },
  openWebsiteText: { color: '#FFFFFF', fontSize: 16, fontWeight: '700' },
  readyRow: { marginTop: 24, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8 },
  readyDot: { width: 8, height: 8, borderRadius: 4, backgroundColor: '#1F8A57' },
  readyText: { color: '#5B6375', fontSize: 13, fontWeight: '600' },
});
