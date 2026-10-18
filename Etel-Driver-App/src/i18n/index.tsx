import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import {
  Alert as RNAlert,
  AlertButton,
  AlertOptions,
  Text as RNText,
  TextInput as RNTextInput,
  TextInputProps,
  TextProps,
} from 'react-native';
import * as SecureStore from 'expo-secure-store';
import { translate as translateWith, Lang } from './translate';

export type { Lang };

// English / Amharic. The screens keep their English strings; `Text`, `TextInput` (placeholder) and `Alert`
// exported from here look each string up in the Amharic dictionary when Amharic is chosen. Anything without an
// entry stays English, so a missing translation can never break a screen.
const KEY = 'etel_lang';

let current: Lang = 'en'; // read by the non-hook helpers (Alert); kept in step by LanguageProvider

export function translate(text: string, lang: Lang = current): string {
  return translateWith(text, lang);
}

interface LanguageValue {
  lang: Lang;
  setLang: (l: Lang) => void;
  toggle: () => void;
  t: (text: string) => string;
}

const LanguageContext = createContext<LanguageValue | undefined>(undefined);

export function LanguageProvider({ children }: { children: React.ReactNode }) {
  const [lang, setLangState] = useState<Lang>('en');

  useEffect(() => {
    SecureStore.getItemAsync(KEY)
      .then((v) => {
        if (v === 'am') { current = 'am'; setLangState('am'); }
      })
      .catch(() => {});
  }, []);

  const setLang = useCallback((l: Lang) => {
    current = l;
    setLangState(l);
    SecureStore.setItemAsync(KEY, l).catch(() => {});
  }, []);
  const toggle = useCallback(() => setLang(current === 'am' ? 'en' : 'am'), [setLang]);
  const t = useCallback((text: string) => translate(text, lang), [lang]);
  const value = useMemo(() => ({ lang, setLang, toggle, t }), [lang, setLang, toggle, t]);
  return <LanguageContext.Provider value={value}>{children}</LanguageContext.Provider>;
}

export function useLanguage(): LanguageValue {
  const ctx = useContext(LanguageContext);
  if (!ctx) throw new Error('useLanguage must be used within LanguageProvider');
  return ctx;
}

function mapChildren(children: React.ReactNode, lang: Lang): React.ReactNode {
  if (typeof children === 'string') return translate(children, lang);
  if (Array.isArray(children)) return children.map((c) => (typeof c === 'string' ? translate(c, lang) : c));
  return children;
}

// Drop-in for react-native's Text: string children are translated.
export function Text(props: TextProps) {
  const ctx = useContext(LanguageContext);
  const lang = ctx ? ctx.lang : 'en';
  return <RNText {...props}>{lang === 'am' ? mapChildren(props.children, lang) : props.children}</RNText>;
}

// Drop-in for react-native's TextInput: the placeholder is translated (typed text never is).
export const TextInput = React.forwardRef<RNTextInput, TextInputProps>(function TextInput(props, ref) {
  const ctx = useContext(LanguageContext);
  const lang = ctx ? ctx.lang : 'en';
  const placeholder = typeof props.placeholder === 'string' && lang === 'am' ? translate(props.placeholder, lang) : props.placeholder;
  return <RNTextInput {...props} ref={ref} placeholder={placeholder} />;
});

// Drop-in for react-native's Alert: title, message and button labels are translated.
export const Alert = {
  alert(title: string, message?: string, buttons?: AlertButton[], options?: AlertOptions) {
    RNAlert.alert(
      translate(title),
      message === undefined ? undefined : translate(message),
      buttons ? buttons.map((b) => ({ ...b, text: b.text ? translate(b.text) : b.text })) : undefined,
      options,
    );
  },
};
