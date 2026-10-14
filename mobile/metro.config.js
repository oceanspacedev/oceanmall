const fs = require('fs');
const path = require('path');
const { getDefaultConfig } = require('expo/metro-config');
const { withNativeWind } = require('nativewind/metro');

/** Joins build problems into one readable build failure. */
function fail(title, lines) {
  throw new Error([title, ...lines].join('\n'));
}

// Release guards: fail the BUILD (not the app) when something that must be real before a store
// submission is missing. `expo start` runs with NODE_ENV=development and is not checked;
// `expo export`, `export:embed` (Xcode Release / gradlew bundleRelease) and EAS Build run with
// NODE_ENV=production and have already loaded .env.production by the time Metro reads this.
if (process.env.NODE_ENV === 'production') {
  // 1. The API host. Keep the rules in sync with lib/config.ts (isUsableHttpsUrl).
  const apiUrl = (process.env.EXPO_PUBLIC_API_URL || '').trim();

  if (!/^https:\/\/[^/\s]+/i.test(apiUrl) || /change-me/i.test(apiUrl)) {
    fail('EXPO_PUBLIC_API_URL is missing, still a CHANGE-ME placeholder, or not an https:// URL.', [
      `Current value: ${apiUrl || '(empty)'}`,
      'Release builds read mobile/.env.production. Set it to the real API, for example',
      'EXPO_PUBLIC_API_URL=https://<production-host>/api/v1, then build again.',
    ]);
  }

  // 2. A support contact. The privacy policy and Apple guideline 1.5 need a way to reach us, and
  //    the in-app "Laporkan" fallback and every "Butuh bantuan?" row hide without one. Mirror
  //    lib/config.ts (keep in sync): the app drops an email that is not email-shaped or contains
  //    "change-me", and strips WhatsApp to digits, so a raw non-empty check would pass a value
  //    that the app then discards and the build would still ship with no contact at all.
  const whatsapp = (process.env.EXPO_PUBLIC_SUPPORT_WHATSAPP || '').replace(/\D/g, '');
  const email = (process.env.EXPO_PUBLIC_SUPPORT_EMAIL || '').trim();
  const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && !/change-me/i.test(email);

  if (whatsapp.length < 8 && !emailOk) {
    fail('No usable support contact for this release build.', [
      'Set EXPO_PUBLIC_SUPPORT_EMAIL and/or EXPO_PUBLIC_SUPPORT_WHATSAPP in mobile/.env.production',
      '(a valid email, or an international number as digits, e.g. 62812xxxxxxxx).',
      'Release builds need a contact: the privacy policy and the store listings point customers to it.',
      `Current values: EXPO_PUBLIC_SUPPORT_EMAIL=${email || '(empty)'}, EXPO_PUBLIC_SUPPORT_WHATSAPP=${whatsapp || '(empty)'}`,
    ]);
  }

  // 3. The legal text must be approved. lib/legal-content.json is the single source of the Terms
  //    and Privacy Policy shown in the app (it is bundled into the app). While it is still a draft or
  //    holds [TODO ...] placeholders, a store build would show "Draf" and placeholder sentences to
  //    reviewers (Apple 2.1 / 5.1.1(i)). Internal tester builds (EAS profile "preview") are exempt
  //    so QA can read the draft; set ALLOW_DRAFT_LEGAL=1 for any other deliberate internal build.
  //    A local Xcode archive sets neither, so it is checked like a store build.
  const internalBuild =
    process.env.EAS_BUILD_PROFILE === 'preview' || process.env.ALLOW_DRAFT_LEGAL === '1';

  if (!internalBuild) {
    const legalFile = path.join(__dirname, 'lib', 'legal-content.json');
    const problems = [];

    try {
      const legal = JSON.parse(fs.readFileSync(legalFile, 'utf8'));
      if (legal.draft !== false) {
        problems.push('"draft" is not false (the text has not been approved).');
      }
      if (/\[TODO/.test(JSON.stringify(legal.docs ?? {}))) {
        problems.push('it still contains [TODO ...] placeholders.');
      }
    } catch (error) {
      problems.push(`it could not be read as JSON (${error.message}).`);
    }

    if (problems.length > 0) {
      fail('The legal text in mobile/lib/legal-content.json is not ready for a store build:', [
        ...problems.map((problem) => `  - ${problem}`),
        'Have the text approved, fill every [TODO ...], and set "draft": false, then build again.',
        'For an internal tester build use the EAS "preview" profile, or set ALLOW_DRAFT_LEGAL=1.',
      ]);
    }
  }
}

const config = getDefaultConfig(__dirname);

module.exports = withNativeWind(config, { input: './global.css', inlineRem: 16 });
