import { explosionDesigns } from "./explosionDesigns";
import { picasoEnhancements } from "./picasoEnhancements";

const originals = [
    {
        id: "pulse",
        number: "01",
        name: "Pulse",
        accent: "#ff8847",
        feature: "Signal rail",
        description: "Orange energy. Every pair in play.",
    },
    {
        id: "neural",
        number: "02",
        name: "Neural",
        accent: "#67adff",
        feature: "Connected intelligence",
        description: "A blue network with AI at its center.",
    },
    {
        id: "terminal",
        number: "03",
        name: "Terminal",
        accent: "#7cfaa8",
        feature: "Trading terminal",
        description: "Dense signals. Pure trading focus.",
    },
    {
        id: "orbit",
        number: "04",
        name: "Orbit",
        accent: "#bc9aff",
        feature: "Market constellation",
        description: "The entire market, in your orbit.",
    },
    {
        id: "prism",
        number: "05",
        name: "Prism",
        accent: "#d4ed7e",
        feature: "Midnight refraction",
        description: "Black glass. A luminous view of every signal.",
    },
];
const features = {
    supernova: "Particle accelerator",
    neon: "Holographic grid",
    reactor: "Industrial core",
    liquid: "Flowing price field",
    citadel: "Capital skyline",
    redline: "Velocity tunnel",
    synapse: "Neural cloud",
    spectrum: "Colorful market map",
    horizon: "Market singularity",
    overdrive: "Command wall",
    "horizon-blue": "Electric blue singularity",
    "supernova-blue": "Blue particle accelerator",
};

export const designCatalog = [
    ...originals.map((design) => ({ ...design, series: "original" })),
    ...explosionDesigns.map((design) => ({
        ...design,
        feature: features[design.id],
        series: "explosion",
    })),
    {
        id: "pulse-blue",
        number: "16",
        name: "Pulse Blue",
        accent: "#17b8ee",
        feature: "ShoeMoney signature edition",
        description: "Your identity. Electric blue. Every pair in play.",
        series: "original",
    },
]
    .sort((a, b) => Number(a.number) - Number(b.number))
    .map((design) => ({
        ...design,
        enhancements: picasoEnhancements[design.id],
        thumbnail: `/design-previews/${design.id}.jpg?v=${design.id === "horizon" || design.id === "horizon-blue" ? "guardian" : "signature-polish"}`,
        preview: `/landing/${design.id}?demo=1`,
    }));
