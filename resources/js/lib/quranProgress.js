import quranMetadata from '../../data/quran-metadata.json';

const surahsById = new Map(quranMetadata.surahs.map((surah) => [surah.id, surah]));
const surahOffsets = new Map();
let nextSurahOffset = 0;

quranMetadata.surahs.forEach((surah) => {
    surahOffsets.set(surah.id, nextSurahOffset);
    nextSurahOffset += surah.ayah_count;
});

export const totalAyahs = quranMetadata.meta.total_ayahs;

export function getSurah(surahId) {
    return surahsById.get(Number(surahId)) ?? null;
}

export function formatQuranNumber(value, language) {
    return new Intl.NumberFormat(language === 'ar' ? 'ar' : 'fr').format(Number(value));
}

export function surahDisplayName(surah, language) {
    if (!surah) return '';

    return language === 'ar'
        ? `${surah.name_ar} (${surah.name})`
        : `${surah.name_fr} (${surah.name_ar})`;
}

export function juzDisplayName(juz, language) {
    const label = language === 'ar' ? juz.label_ar : juz.label;

    return label.replace(/\d+/g, (number) => formatQuranNumber(number, language));
}

export function formatPortionRange(range, language) {
    const startSurah = getSurah(range?.surah_start);
    const endSurah = getSurah(range?.surah_end);
    const startAyah = Number(range?.ayah_start);
    const endAyah = Number(range?.ayah_end);

    if (
        !startSurah
        || !endSurah
        || !Number.isInteger(startAyah)
        || !Number.isInteger(endAyah)
        || startAyah < 1
        || endAyah < 1
        || startAyah > startSurah.ayah_count
        || endAyah > endSurah.ayah_count
        || startSurah.id > endSurah.id
        || (startSurah.id === endSurah.id && startAyah > endAyah)
    ) {
        return '';
    }

    const startNumber = formatQuranNumber(startAyah, language);
    const endNumber = formatQuranNumber(endAyah, language);
    const startLabel = `${surahDisplayName(startSurah, language)} ${startNumber}`;

    if (startSurah.id === endSurah.id) {
        return `${surahDisplayName(startSurah, language)} ${startNumber}–${endNumber}`;
    }

    return `${startLabel} → ${surahDisplayName(endSurah, language)} ${endNumber}`;
}

function positionIndex(position) {
    const offset = surahOffsets.get(Number(position?.surah));
    const ayah = Number(position?.ayah);
    const surah = getSurah(position?.surah);

    if (!surah || !Number.isInteger(ayah) || ayah < 1 || ayah > surah.ayah_count) {
        return null;
    }

    return offset + ayah - 1;
}

function sessionInterval(range) {
    const start = positionIndex({ surah: range.surah_start, ayah: range.ayah_start });
    const end = positionIndex({ surah: range.surah_end, ayah: range.ayah_end });

    if (start === null || end === null || start > end) {
        return null;
    }

    return { start, end: end + 1 };
}

export function getProgressPreview(sessions, proposedRange = null, excludeSessionId = null) {
    const intervals = sessions
        .filter((session) => session.id !== excludeSessionId)
        .map(sessionInterval)
        .filter(Boolean);

    if (proposedRange) {
        const proposedInterval = sessionInterval(proposedRange);
        if (proposedInterval) intervals.push(proposedInterval);
    }

    intervals.sort((first, second) => first.start - second.start);
    const mergedIntervals = [];

    intervals.forEach((interval) => {
        const previous = mergedIntervals[mergedIntervals.length - 1];
        if (!previous || interval.start > previous.end) {
            mergedIntervals.push({ ...interval });
        } else {
            previous.end = Math.max(previous.end, interval.end);
        }
    });

    const coveredAyahs = mergedIntervals.reduce(
        (total, interval) => total + interval.end - interval.start,
        0,
    );
    const hasCoverage = mergedIntervals.length > 0;
    const contiguousAyahs = mergedIntervals[0]?.start === 0
        ? mergedIntervals[0].end
        : 0;
    const surahProgress = quranMetadata.surahs.map((surah) => {
        const start = surahOffsets.get(surah.id);
        const end = start + surah.ayah_count;
        const ranges = mergedIntervals
            .map((interval) => {
                const rangeStart = Math.max(interval.start, start);
                const rangeEnd = Math.min(interval.end, end);

                return rangeStart < rangeEnd
                    ? {
                        start: rangeStart - start + 1,
                        end: rangeEnd - start,
                    }
                    : null;
            })
            .filter(Boolean);
        const coveredAyahsInSurah = ranges.reduce(
            (covered, range) => covered + range.end - range.start + 1,
            0,
        );

        return {
            surah,
            coveredAyahs: coveredAyahsInSurah,
            ranges,
            isComplete: coveredAyahsInSurah === surah.ayah_count,
        };
    });
    const juzProgress = quranMetadata.juz.map((juz) => {
        const start = positionIndex(juz.start);
        const endIndex = positionIndex(juz.end);
        const end = endIndex + (juz.end.inclusive === 'inclusive' ? 1 : 0);
        const coveredAyahsInJuz = mergedIntervals.reduce((covered, interval) => (
            covered + Math.max(0, Math.min(interval.end, end) - Math.max(interval.start, start))
        ), 0);

        return {
            juz,
            coveredAyahs: coveredAyahsInJuz,
            totalAyahs: end - start,
            isComplete: coveredAyahsInJuz === end - start,
        };
    });
    const currentJuz = quranMetadata.juz.find((juz) => {
        const endIndex = positionIndex(juz.end);
        const endExclusive = endIndex + (juz.end.inclusive === 'inclusive' ? 1 : 0);

        return contiguousAyahs < endExclusive;
    }) ?? quranMetadata.juz[quranMetadata.juz.length - 1];

    return {
        coveredAyahs,
        totalAyahs,
        percentage: Math.round((coveredAyahs / totalAyahs) * 100),
        currentJuz: contiguousAyahs > 0 ? currentJuz : null,
        completeSurahCount: surahProgress.filter((progress) => progress.isComplete).length,
        surahProgress,
        inProgressSurahs: surahProgress.filter((progress) => (
            progress.coveredAyahs > 0 && !progress.isComplete
        )),
        completeJuzCount: juzProgress.filter((progress) => progress.isComplete).length,
        inProgressJuzs: juzProgress.filter((progress) => (
            progress.coveredAyahs > 0 && !progress.isComplete
        )),
        hasCoverage: mergedIntervals.length > 0,
    };
}

export function searchSurahs(query) {
    const searchTerm = query.trim().toLocaleLowerCase();
    if (!searchTerm) return quranMetadata.surahs;

    return quranMetadata.surahs.filter((surah) => (
        String(surah.id).includes(searchTerm)
        || surah.name.toLocaleLowerCase().includes(searchTerm)
        || surah.name_fr.toLocaleLowerCase().includes(searchTerm)
        || surah.name_ar.includes(searchTerm)
    ));
}

export { quranMetadata };
