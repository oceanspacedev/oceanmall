import { parseApiDate } from '@/lib/format';
import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AppState } from 'react-native';

/** The countdown turns red below this. */
export const URGENT_BELOW_MS = 60 * 60 * 1000;

export type CountdownState = {
  hh: string;
  mm: string;
  ss: string;
  /** 'HH:MM:SS' */
  text: string;
  /** Minute-precision sentence for screen readers, so they are not interrupted every second. */
  spoken: string;
  remainingMs: number;
  expired: boolean;
  /** Under 60 minutes left (never true once expired). */
  urgent: boolean;
};

function pad2(value: number): string {
  return value < 10 ? `0${value}` : String(value);
}

/** Pure: the countdown for an end time at a given moment. */
export function computeCountdown(endMs: number, nowMs: number): CountdownState {
  const remainingMs = Math.max(0, endMs - nowMs);
  const expired = remainingMs <= 0;
  const totalSeconds = Math.ceil(remainingMs / 1000);
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  let spoken: string;
  if (expired) {
    spoken = 'waktu habis';
  } else if (hours > 0) {
    spoken = `${hours} jam ${minutes} menit`;
  } else if (minutes > 0) {
    spoken = `${minutes} menit`;
  } else {
    spoken = 'kurang dari 1 menit';
  }

  return {
    hh: pad2(hours),
    mm: pad2(minutes),
    ss: pad2(seconds),
    text: `${pad2(hours)}:${pad2(minutes)}:${pad2(seconds)}`,
    spoken,
    remainingMs,
    expired,
    urgent: !expired && remainingMs < URGENT_BELOW_MS,
  };
}

/**
 * Time left until a payment deadline, ticking once a second.
 *
 * Returns null when there is no usable deadline (missing, or not a date), so callers render
 * nothing instead of "NaN:NaN". The deadline is parsed with parseApiDate, so a zone-less string
 * from the gateway is read as WIB. Every tick recomputes from the clock (it never counts down a
 * stored number), and the app coming back to the foreground ticks immediately, so a long
 * background stay shows the right time at once.
 *
 * `onExpire` runs once, when the deadline passes while this is mounted (including a tick that
 * finds it passed after the app was backgrounded). A deadline that is already over on mount does
 * not call it: nothing changed, and the caller already has the expired state to render.
 */
export function useCountdown(expiry?: string | null, onExpire?: () => void): CountdownState | null {
  const end = useMemo(() => parseApiDate(expiry)?.getTime() ?? null, [expiry]);
  const [now, setNow] = useState(() => Date.now());
  const onExpireRef = useRef(onExpire);

  useEffect(() => {
    onExpireRef.current = onExpire;
  }, [onExpire]);

  useEffect(() => {
    if (end === null) {
      return;
    }

    let armed = Date.now() < end;
    let timer: ReturnType<typeof setInterval> | undefined;

    const stop = () => {
      if (timer) {
        clearInterval(timer);
        timer = undefined;
      }
    };
    const tick = () => {
      const current = Date.now();
      setNow(current);
      if (current >= end) {
        stop();
        if (armed) {
          armed = false;
          onExpireRef.current?.();
        }
      }
    };

    tick();
    if (Date.now() < end) {
      timer = setInterval(tick, 1000);
    }
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active') {
        tick();
      }
    });

    return () => {
      stop();
      subscription.remove();
    };
  }, [end]);

  return end === null ? null : computeCountdown(end, now);
}

/**
 * Runs `task` every `intervalMs` while `enabled` and the screen is focused, and once more each
 * time the app returns to the foreground (the customer paid in a banking app and came back).
 * Nothing runs while the app is in the background, and a slow task is never started twice at
 * once. The task reports its own problems: a rejection here is swallowed, because a background
 * refresh must not surface errors on its own.
 */
export function usePolling(
  task: () => Promise<unknown> | unknown,
  enabled: boolean,
  intervalMs = 8000
): void {
  const taskRef = useRef(task);

  useEffect(() => {
    taskRef.current = task;
  }, [task]);

  useFocusEffect(
    useCallback(() => {
      if (!enabled) {
        return;
      }

      let running = false;
      const run = async () => {
        if (running) {
          return;
        }
        running = true;
        try {
          await taskRef.current();
        } catch {
          // Best effort.
        } finally {
          running = false;
        }
      };

      const timer = setInterval(() => {
        if (AppState.currentState === 'active') {
          void run();
        }
      }, intervalMs);
      const subscription = AppState.addEventListener('change', (state) => {
        if (state === 'active') {
          void run();
        }
      });

      return () => {
        clearInterval(timer);
        subscription.remove();
      };
    }, [enabled, intervalMs])
  );
}
