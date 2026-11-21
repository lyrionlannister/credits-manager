import type { FC } from 'react';
import type { ApexOptions } from 'apexcharts';
import Chart from 'react-apexcharts';

import type { LineChartProps } from '../props/line-chart-props';

/**
 * `type="area"` con relleno degradado: el comportamiento real de `StatisticsChart.tsx` del template
 * (ApexCharts usa el `type` del componente, no el de `options.chart`, pese a que ahí diga "line").
 * La leyenda se deja visible (el template la oculta): nuestras series no son decorativas, son datos
 * distintos (ej. Colocación/Recaudo/Gastos) que el usuario necesita poder identificar por color.
 */
export const LineChart: FC<LineChartProps> = ({ categories, series, height = 310, valueFormatter }) => {
    const formatear = valueFormatter ?? ((value: number) => value.toLocaleString('es-CO'));

    const options: ApexOptions = {
        chart: { type: 'area', fontFamily: 'Outfit, sans-serif', toolbar: { show: false } },
        colors: series.map((serie) => serie.color),
        stroke: { curve: 'straight', width: 2 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.55, opacityTo: 0 } },
        markers: { size: 0, strokeWidth: 2, strokeColors: '#fff', hover: { size: 6 } },
        dataLabels: { enabled: false },
        xaxis: {
            type: 'category',
            categories,
            axisBorder: { show: false },
            axisTicks: { show: false },
            tooltip: { enabled: false },
        },
        yaxis: { labels: { formatter: formatear, style: { fontSize: '12px', colors: ['#6B7280'] } } },
        grid: { xaxis: { lines: { show: false } }, yaxis: { lines: { show: true } } },
        legend: { position: 'top', horizontalAlign: 'right', fontFamily: 'Outfit, sans-serif', markers: { size: 6 } },
        tooltip: { y: { formatter: formatear } },
    };

    // ApexCharts redibuja con retardo tras un resize: sin recorte, el SVG viejo desborda la card un instante.
    return (
        <div className="min-w-0 overflow-hidden">
            <Chart type="area" height={height} series={series.map(({ name, data }) => ({ name, data }))} options={options} />
        </div>
    );
};
