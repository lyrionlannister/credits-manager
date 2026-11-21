import type { FC } from 'react';
import type { ApexOptions } from 'apexcharts';
import Chart from 'react-apexcharts';

import type { StackedBarChartProps } from '../props/stacked-bar-chart-props';

export const StackedBarChart: FC<StackedBarChartProps> = ({ categories, series, height = 300, valueFormatter, integerValues = false }) => {
    const formatear = valueFormatter ?? ((value: number) => value.toLocaleString('es-CO'));

    const options: ApexOptions = {
        chart: { type: 'bar', fontFamily: 'Outfit, sans-serif', stacked: true, toolbar: { show: false }, zoom: { enabled: false } },
        colors: series.map((serie) => serie.color),
        plotOptions: { bar: { borderRadius: 10, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last', columnWidth: '39%' } },
        dataLabels: { enabled: false },
        xaxis: {
            categories,
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: { labels: { formatter: formatear }, ...(integerValues && { forceNiceScale: true, decimalsInFloat: 0 }) },
        grid: { yaxis: { lines: { show: true } } },
        fill: { opacity: 1 },
        legend: {
            position: 'top',
            horizontalAlign: 'right',
            fontFamily: 'Outfit, sans-serif',
            fontSize: '14px',
            fontWeight: 400,
            markers: { size: 5, shape: 'circle', strokeWidth: 0 },
            itemMargin: { horizontal: 10, vertical: 0 },
        },
        tooltip: { x: { show: false }, y: { formatter: formatear } },
    };

    // ApexCharts redibuja con retardo tras un resize: sin recorte, el SVG viejo desborda la card un instante.
    return (
        <div className="min-w-0 overflow-hidden">
            <Chart type="bar" height={height} series={series.map(({ name, data }) => ({ name, data }))} options={options} />
        </div>
    );
};
