import type { CSSProperties } from 'react';

const COLORS = ['#f5a773', '#6ab9cf', '#2a9dc4', '#ee8a43', '#4c63ba', '#12935a'];

/** Pseudoaleatorio determinístico por índice: el confeti es el mismo en cada render (sin parpadeos). */
const rand = (i: number, salt: number) => {
    const x = Math.sin(i * 12.9898 + salt * 78.233) * 43758.5453;
    return x - Math.floor(x);
};

/** Ráfaga de confeti decorativa. Cada `burstKey` nuevo la vuelve a disparar; no captura clics ni lo leen los lectores de pantalla. */
export function Confetti({ burstKey }: { burstKey: number }) {
    if (burstKey === 0) return null;

    return (
        <div key={burstKey} aria-hidden="true" className="pointer-events-none fixed inset-0 z-[60] overflow-hidden">
            <div className="absolute left-1/2 top-[38%]">
                {Array.from({ length: 36 }, (_, i) => {
                    const angle = rand(i, 1) * Math.PI * 2;
                    const distance = 140 + rand(i, 2) * 260;

                    return (
                        <span
                            key={i}
                            className="confetti-piece absolute block"
                            style={
                                {
                                    '--dx': `${Math.cos(angle) * distance}px`,
                                    '--dy': `${Math.sin(angle) * distance + 90}px`,
                                    '--rot': `${(rand(i, 3) - 0.5) * 900}deg`,
                                    width: 6 + rand(i, 4) * 6,
                                    height: 8 + rand(i, 5) * 8,
                                    background: COLORS[i % COLORS.length],
                                    borderRadius: i % 3 === 0 ? '9999px' : '2px',
                                } as CSSProperties
                            }
                        />
                    );
                })}
            </div>
        </div>
    );
}
