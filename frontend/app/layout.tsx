import "./globals.css";
import type { Metadata, Viewport } from "next";
import { AuthProvider } from "./lib/auth";
import { I18nProvider } from "./lib/i18n";
import { THEME_INIT_SCRIPT, ThemeProvider } from "./lib/theme";
import { ToastProvider } from "../components/ToastProvider";
import ClientErrorReporter from "../components/ClientErrorReporter";
import ErpRealtimeBridge from "../components/ErpRealtimeBridge";

export const metadata: Metadata = {
  title: "Store ERP",
  description: "Do‘kon va savdo boshqaruv tizimi",
};

export const viewport: Viewport = { width: "device-width", initialScale: 1, viewportFit: "cover" };

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="uz" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: THEME_INIT_SCRIPT }} />
      </head>
      <body>
        <ThemeProvider>
          <ToastProvider>
            <ClientErrorReporter />
            <ErpRealtimeBridge />
            <AuthProvider>
              <I18nProvider>{children}</I18nProvider>
            </AuthProvider>
          </ToastProvider>
        </ThemeProvider>
      </body>
    </html>
  );
}
