import React from 'react';
import { BarsChart, ChartCard, useChartColors } from './DashboardCharts';
import { useLanguage } from '../hooks/useLanguage';

const PROGRESS_BUCKET_KEYS = [
    'none',
    'started',
    'developing',
    'strong',
    'nearly_complete',
    'complete',
];

function getCompactStudentName(fullName, lang) {
    const nameParts = fullName.trim().split(/\s+/);
    const firstName = nameParts[0] ?? fullName;
    const compactFirstName = firstName.length > 5 ? `${firstName.slice(0, 4)}…` : firstName;

    if (lang === 'ar' || nameParts.length < 2) {
        return compactFirstName;
    }

    return `${compactFirstName} ${nameParts.at(-1)[0]}.`;
}

export default function StudentProgressCharts({ progress }) {
    const { lang, t } = useLanguage();
    const colors = useChartColors();
    const numberFormat = new Intl.NumberFormat(lang === 'ar' ? 'ar-MA-u-nu-latn' : 'fr-MA');
    const distributionData = PROGRESS_BUCKET_KEYS.map((key) => ({
        key,
        name: t.dashboardStats.charts.progressBuckets[key],
        value: progress.distribution[key],
        color: colors.green,
    }));
    const topStudentData = progress.top_students.map((student, index) => ({
        key: student.student_id,
        name: getCompactStudentName(student.student_name, lang),
        detail: [student.student_name, student.room_name].filter(Boolean).join(' · '),
        value: student.percentage,
        color: index === 0 ? colors.green : colors.gray,
    }));
    const enrolledStudentCount = distributionData.reduce((sum, item) => sum + item.value, 0);

    return (
        <div className="grid w-full grid-cols-1 gap-4 xl:grid-cols-2">
            <ChartCard
                title={t.dashboardStats.charts.studentProgress}
                subtitle={t.dashboardStats.charts.studentProgressHint}
            >
                {enrolledStudentCount > 0 ? (
                    <BarsChart
                        data={distributionData}
                        colors={colors}
                        height={200}
                        valueFormatter={(value) => numberFormat.format(value)}
                    />
                ) : (
                    <p
                        role="status"
                        className="flex min-h-[180px] items-center justify-center text-center text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim"
                    >
                        {t.dashboardStats.charts.noStudents}
                    </p>
                )}
            </ChartCard>

            <ChartCard
                title={t.dashboardStats.charts.topProgress}
                subtitle={t.dashboardStats.charts.topProgressHint}
            >
                {topStudentData.length > 0 ? (
                    <BarsChart
                        data={topStudentData}
                        colors={colors}
                        height={220}
                        axisFormatter={(value) => `${numberFormat.format(value)}%`}
                        valueFormatter={(value) => `${numberFormat.format(value)}%`}
                    />
                ) : (
                    <p
                        role="status"
                        className="flex min-h-[180px] items-center justify-center text-center text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim"
                    >
                        {t.dashboardStats.charts.noProgress}
                    </p>
                )}
            </ChartCard>
        </div>
    );
}
