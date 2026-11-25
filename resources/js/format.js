export const money = (value) => '₹' + Number(value ?? 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });

export const displayName = (person) =>
    [person.name, person.middle_name, person.last_name].map((part) => part?.trim()).filter(Boolean).join(' ');

// Dates are plain "YYYY-MM-DD" days, so format them without a time zone shift.
const day = (date, options) => new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC', ...options }).format(new Date(`${date}T00:00:00Z`));

export const longDate = (date) =>
    `${day(date, { weekday: 'long' })}, ${day(date, { day: 'numeric', month: 'long', year: 'numeric' })}`;

export const weekday = (date) => day(date, { weekday: 'long' });

export const shortDate = (date) => day(date, { day: 'numeric', month: 'short', year: 'numeric' });

// "2026-11" or "2026-11-01" → "November 2026"
export const monthName = (month) => day(`${month.slice(0, 7)}-01`, { month: 'long', year: 'numeric' });

// This month first.
export const lastMonths = (today, many) => Array.from({ length: many }, (_, back) => {
    const [year, month] = today.split('-').map(Number);
    return new Date(Date.UTC(year, month - 1 - back, 1)).toISOString().slice(0, 7);
});
