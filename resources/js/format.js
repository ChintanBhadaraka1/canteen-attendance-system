export const money = (value) => '₹' + Number(value ?? 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });

export const displayName = (person) =>
    [person.name, person.middle_name, person.last_name].map((part) => part?.trim()).filter(Boolean).join(' ');

// Dates are plain "YYYY-MM-DD" days, so format them without a time zone shift.
const day = (date, options) => new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC', ...options }).format(new Date(`${date}T00:00:00Z`));

export const longDate = (date) =>
    `${day(date, { weekday: 'long' })}, ${day(date, { day: 'numeric', month: 'long', year: 'numeric' })}`;

export const shortDate = (date) => day(date, { day: 'numeric', month: 'short', year: 'numeric' });
