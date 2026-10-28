import type { FC } from 'react';
import type { ApexOptions } from 'apexcharts';
import Chart from 'react-apexcharts';

import type { DonutChartProps } from '../props/donut-chart-props';

export const DonutChart: FC<DonutChartProps> = ({
    series,
    labels,
    colors,
    totalLabel,
    totalValue,
    valueFormatter = (value) => value.toLocaleString('es-CO'),
    height = 260,
    showLegend = true,
}) => {
    const options: ApexOptions = {
        chart: { type: 'donut', fontFamily: 'Outfit, sans-serif' },
        labels,
        colors,
        stroke: { show: false, width: 4, colors: ['transparent'] },
        dataLabels: { enabled: false },
        states: {
            hover: { filter: { type: 'none' } },
            active: { filter: { type: 'none' } },
        },
        tooltip: {
            y: { formatter: (value) => valueFormatter(value ?? 0) },
        },
        legend: {
            show: showLegend,
            position: 'bottom',
            horizontalAlign: 'center',
            fontFamily: 'Outfit, sans-serif',
            fontSize: '14px',
            fontWeight: 400,
            markers: { size: 6, shape: 'circle', strokeWidth: 0 },
            itemMargin: { horizontal: 10, vertical: 6 },
        },
        plotOptions: {
            pie: {
                expandOnClick: false,
                donut: {
                    size: '65%',
                    background: 'transparent',
                    labels: {
                        show: true,
                        name: { show: true, fontSize: '13px' },
                        value: { show: true, fontSize: '20px', fontWeight: 700, formatter: (value) => valueFormatter(Number(value)) },
                        total: { show: false, label: totalLabel, formatter: () => totalValue },
                    },
                },
            },
        },
    };

    return (
        <div id="chartDarkStyle">
            <Chart type="donut" height={height} series={series} options={options} />
            <div className="-mt-2 text-center">
                <p className="text-theme-xs text-gray-500 dark:text-gray-400">{totalLabel}</p>
                <p className="mt-0.5 text-lg font-semibold text-gray-800 dark:text-white/90">{totalValue}</p>
            </div>
        </div>
    );
};
