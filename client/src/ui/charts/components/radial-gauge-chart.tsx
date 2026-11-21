import type { FC } from 'react';
import type { ApexOptions } from 'apexcharts';
import Chart from 'react-apexcharts';

import type { RadialGaugeChartProps } from '../props/radial-gauge-chart-props';

/** El color del `dataLabel` central queda fijo en JS (`#1D2939`, el mismo valor que trae
 * `MonthlyTarget` del template TailAdmin) porque ApexCharts dibuja en `<svg>`, no en DOM
 * estilable — el contraste real en modo oscuro lo resuelve la regla ya existente en
 * `index.css` `#chartDarkStyle .apexcharts-datalabels-group .apexcharts-text`. */
export const RadialGaugeChart: FC<RadialGaugeChartProps> = ({ percentage, color, height = 330 }) => {
    const valor = Math.min(100, Math.max(0, percentage));

    const options: ApexOptions = {
        chart: { type: 'radialBar', fontFamily: 'Outfit, sans-serif', sparkline: { enabled: true } },
        colors: [color],
        plotOptions: {
            radialBar: {
                startAngle: -85,
                endAngle: 85,
                hollow: { size: '80%' },
                track: { background: '#E4E7EC', strokeWidth: '100%', margin: 5 },
                dataLabels: {
                    name: { show: false },
                    value: {
                        offsetY: -40,
                        fontSize: '36px',
                        fontWeight: 600,
                        color: '#1D2939',
                        formatter: (val) => `${val.toFixed(0)}%`,
                    },
                },
            },
        },
        fill: { type: 'solid', colors: [color] },
        stroke: { lineCap: 'round' },
        labels: ['Porcentaje'],
    };

    // apexcharts 8 ya no recorta el lienzo del semicírculo: sin esto queda media altura en blanco bajo el arco.
    return (
        <div id="chartDarkStyle" className="min-w-0 overflow-hidden" style={{ height: height / 2 }}>
            <Chart type="radialBar" height={height} series={[valor]} options={options} />
        </div>
    );
};
