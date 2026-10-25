"use client";

import {
  createContext,
  type ReactNode,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";

import { api, tokenStore } from "./api";


export type Role = {
  id: number;
  name: string;
  slug: string;
};

export type AuthUser = {
  id: number;
  name: string;
  email: string;
  status: string;
  roles: Role[];
};

type MeResponse = {
  user: AuthUser;
  permissions: string[];
};

type LoginResponse = MeResponse & {
  token?: string;
  two_factor_required?: boolean;
  challenge_token?: string;
};

type AuthContextValue = {
  user: AuthUser | null;
  permissions: string[];
  loading: boolean;
  login: (email: string, password: string) => Promise<boolean>;
  twoFactorChallenge: string | null;
  verifyTwoFactor: (code: string) => Promise<void>;
  logout: () => Promise<void>;
  can: (permission: string) => boolean;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({
  children,
}: {
  children: ReactNode;
}) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [permissions, setPermissions] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);

  const [twoFactorChallenge, setTwoFactorChallenge] =
    useState<string | null>(null);

  // Mavjud token orqali foydalanuvchini tekshirish.
  useEffect(() => {
    if (!tokenStore.get()) {
      setLoading(false);
      return;
    }

    api<MeResponse>("/auth/me")
      .then((response) => {
        setUser(response.user);
        setPermissions(response.permissions ?? []);
      })
      .catch(() => {
        tokenStore.clear();
        setUser(null);
        setPermissions([]);
      })
      .finally(() => {
        setLoading(false);
      });
  }, []);

  // Muvaffaqiyatli login natijasini saqlash.
  const acceptLogin = useCallback((response: LoginResponse) => {
    if (!response.token || !response.user) {
      throw new Error("Login javobi noto‘g‘ri.");
    }

    tokenStore.set(response.token);

    setUser(response.user);
    setPermissions(response.permissions ?? []);
    setTwoFactorChallenge(null);
  }, []);

  // Email va parol orqali login.
  const login = useCallback(
    async (
      email: string,
      password: string,
    ): Promise<boolean> => {
      const response = await api<LoginResponse>("/auth/login", {
        method: "POST",
        body: {
          email,
          password,
        },
      });

      if (
        response.two_factor_required &&
        response.challenge_token
      ) {
        setTwoFactorChallenge(response.challenge_token);
        return false;
      }

      acceptLogin(response);

      return true;
    },
    [acceptLogin],
  );


  // Ikki bosqichli autentifikatsiyani tasdiqlash.
  const verifyTwoFactor = useCallback(
    async (code: string): Promise<void> => {
      if (!twoFactorChallenge) {
        throw new Error(
          "2FA tasdiqlash sessiyasi topilmadi. Qayta kiring.",
        );
      }
      const response = await api<LoginResponse>(
        "/auth/two-factor/verify",
        {
          method: "POST",
          body: {
            challenge_token: twoFactorChallenge,
            code,
          },
        },
      );

      acceptLogin(response);
    },
    [twoFactorChallenge, acceptLogin],
  );

  // Tizimdan chiqish.
  const logout = useCallback(async (): Promise<void> => {
    try {
      await api("/auth/logout", {
        method: "POST",
      });
    } catch {
      // Token yaroqsiz bo‘lsa ham chiqish davom etadi.
    } finally {

      tokenStore.clear();

      setUser(null);
      setPermissions([]);
      setTwoFactorChallenge(null);
    }
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      permissions,
      loading,
      login,
      twoFactorChallenge,
      verifyTwoFactor,
      logout,
      can: (permission) => permissions.includes(permission),
    }),
    [
      user,
      permissions,
      loading,
      login,
      twoFactorChallenge,
      verifyTwoFactor,
      logout,
    ],
  );

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error(
      "useAuth faqat AuthProvider ichida ishlatiladi",
    );
  }

  return context;
}