// Whole thousandths and whole paise, read from text without decimals in between.
function digits(text, whole, decimals) {
    const parts = String(text ?? '').trim().match(new RegExp(`^(\\d{1,${whole}})(?:\\.(\\d{1,${decimals}}))?$`));
    return parts ? Number(parts[1] + (parts[2] ?? '').padEnd(decimals, '0')) : null;
}
export const parseQuantity = (text) => digits(text, 7, 3);
export const parseRupees = (text) => digits(text, 8, 2);

const show = (number, decimals) => {
    const text = String(Math.abs(number)).padStart(decimals + 1, '0');
    return [(number < 0 ? '−' : ''), text.slice(0, -decimals), text.slice(-decimals)];
};
export const rupees = (paise) => {
    const [sign, whole, part] = show(paise, 2);
    return `${sign}₹${Number(whole).toLocaleString('en-IN')}.${part}`;
};
export const quantity = (number) => {
    const [sign, whole, part] = show(number, 3);
    return `${sign}${whole}.${part}`.replace(/\.?0+$/, '');
};
// "2.5 kg", "3 pieces"
export const amountOf = (number, unit) => `${quantity(number)} ${unit}${unit !== 'kg' && Math.abs(number) !== 1000 ? 's' : ''}`;

// Thousandths of a typed row; null when it cannot be used.
export function rowQuantity(row, item) {
    const typed = parseQuantity(row.quantity);
    const size = item?.units.find(({ key }) => key === row.unit)?.size;
    if (typed === null || size === undefined) return null;
    const product = BigInt(typed) * BigInt(size);
    return product % 1000n === 0n ? Number(product / 1000n) : null;
}
