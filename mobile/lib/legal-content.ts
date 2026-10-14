/*
 * TODO(OWNER / LEGAL COUNSEL) - READ BEFORE SHIPPING.
 *
 * THIS IS A DRAFT, NOT LEGAL TEXT. Every sentence was written from facts the app and its backend
 * really have (what is collected, who processes payment and delivery, how sessions work: one
 * device per account). No lawyer has reviewed it, and it deliberately makes no claim about things
 * nobody has decided: the legal entity name and address, cancellations and returns, warranty,
 * data retention periods, how an account deletion request is handled, governing law and dispute
 * handling. It also makes no claim about features the app does not have (there is no in-app
 * account deletion and no per-review report yet, see README.md "Open blockers").
 *
 * The text itself lives in lib/legal-content.json, bundled into the app. {{contact}} in a body
 * becomes the real support channel (see CONTACT below). The Laravel storefront does NOT read this
 * file and has no public legal pages yet, so the stores cannot link to this text until a public
 * URL exists (README.md "Open blockers").
 *
 * Before the first store submission:
 *   1. Have counsel (or the owner, if they accept the risk) rewrite or approve every section.
 *   2. Fill the [TODO ...] markers in legal-content.json (they are shown on screen on purpose).
 *   3. Set "draft" to false in legal-content.json. While it is true, both screens show a "Draf"
 *      notice and metro.config.js refuses to bundle a store build (EAS profile "production",
 *      Xcode Release, expo export). Internal "preview" builds still show the draft so testers can
 *      read it.
 *   4. Publish the same text at public URLs and enter them in App Store Connect and Play Console
 *      (not built yet, see README.md "Open blockers").
 */

import legal from '@/lib/legal-content.json';
import { SUPPORT_EMAIL, SUPPORT_WHATSAPP } from '@/lib/config';
import Constants from 'expo-constants';
import { Platform } from 'react-native';

/** True until the text has been reviewed and approved. Shows a notice on both legal screens. */
export const LEGAL_IS_DRAFT: boolean = legal.draft;

export type LegalSlug = 'syarat-ketentuan' | 'kebijakan-privasi';

export type LegalDoc = {
  title: string;
  /** ISO date of the last edit of the text. */
  updated: string;
  sections: { heading: string; body: string }[];
};

/**
 * How a customer reaches us about their data: the support email, else the WhatsApp number. Only a
 * development build can have neither (metro.config.js refuses a release build without one), and
 * then the text points at the in-app Pusat bantuan menu.
 */
const CONTACT = SUPPORT_EMAIL
  ? `email ke ${SUPPORT_EMAIL}`
  : SUPPORT_WHATSAPP
    ? `WhatsApp ke +${SUPPORT_WHATSAPP}`
    : 'tim OceanMall lewat menu Pusat bantuan';

function withContact(doc: LegalDoc): LegalDoc {
  return {
    ...doc,
    sections: doc.sections.map((section) => ({
      ...section,
      body: section.body.split('{{contact}}').join(CONTACT),
    })),
  };
}

export const LEGAL_DOCS: Record<LegalSlug, LegalDoc> = {
  'syarat-ketentuan': withContact(legal.docs['syarat-ketentuan']),
  'kebijakan-privasi': withContact(legal.docs['kebijakan-privasi']),
};

export function isLegalSlug(value: unknown): value is LegalSlug {
  return value === 'syarat-ketentuan' || value === 'kebijakan-privasi';
}

// App version, shown in the Akun footer and on the Tentang screen.
const config = Constants.expoConfig;

/** Marketing version from app.json, for example 1.0.0. */
export const APP_VERSION: string = config?.version ?? '1.0.0';

const BUILD_NUMBER: string | undefined = Platform.select({
  ios: config?.ios?.buildNumber,
  android: config?.android?.versionCode != null ? String(config.android.versionCode) : undefined,
});

/** "Versi 1.0.0 (1)": the version plus the store build number when app.json sets one. */
export const APP_VERSION_LABEL: string = BUILD_NUMBER
  ? `Versi ${APP_VERSION} (${BUILD_NUMBER})`
  : `Versi ${APP_VERSION}`;
