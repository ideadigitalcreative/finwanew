import { ChevronDown } from "lucide-react";
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from "recharts";
import { useState } from "react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

const monthlyData = [
  { period: "Jan", income: 4500, expense: 2800, space: 1200 },
  { period: "Feb", income: 5200, expense: 3200, space: 1400 },
  { period: "Mar", income: 4800, expense: 2500, space: 1100 },
  { period: "Apr", income: 6200, expense: 3800, space: 1600 },
  { period: "Mei", income: 9560, expense: 4200, space: 1800 },
  { period: "Jun", income: 7500, expense: 4800, space: 2000 },
  { period: "Jul", income: 8200, expense: 5200, space: 2200 },
  { period: "Agu", income: 7800, expense: 4500, space: 1900 },
  { period: "Sep", income: 6500, expense: 3800, space: 1500 },
  { period: "Okt", income: 5800, expense: 3200, space: 1300 },
  { period: "Nov", income: 6200, expense: 3500, space: 1400 },
  { period: "Des", income: 7000, expense: 4000, space: 1700 },
];

const weeklyData = [
  { period: "Mg 1", income: 2200, expense: 1400, space: 600 },
  { period: "Mg 2", income: 2800, expense: 1600, space: 800 },
  { period: "Mg 3", income: 2400, expense: 1200, space: 500 },
  { period: "Mg 4", income: 3100, expense: 1900, space: 900 },
];

const dailyData = [
  { period: "Sen", income: 850, expense: 420, space: 180 },
  { period: "Sel", income: 920, expense: 380, space: 200 },
  { period: "Rab", income: 780, expense: 510, space: 150 },
  { period: "Kam", income: 1100, expense: 620, space: 280 },
  { period: "Jum", income: 1250, expense: 480, space: 320 },
  { period: "Sab", income: 680, expense: 350, space: 120 },
  { period: "Min", income: 420, expense: 280, space: 90 },
];

type FilterType = "Harian" | "Mingguan" | "Bulanan";

const CustomTooltip = ({ active, payload }: any) => {
  if (active && payload && payload.length) {
    const labels: Record<string, string> = {
      income: "Pemasukan",
      expense: "Pengeluaran", 
      space: "Tabungan"
    };
    return (
      <div className="bg-primary/80 backdrop-blur-xl text-primary-foreground px-4 py-3 rounded-2xl shadow-2xl border border-primary-foreground/10">
        <p className="text-xs font-medium mb-1 opacity-80">{labels[payload[0].dataKey] || payload[0].name}</p>
        <p className="text-base font-bold">Rp{payload[0].value.toLocaleString("id-ID")}.000</p>
      </div>
    );
  }
  return null;
};

const MoneyFlowChart = () => {
  const [filter, setFilter] = useState<FilterType>("Bulanan");

  const getData = () => {
    switch (filter) {
      case "Harian":
        return dailyData;
      case "Mingguan":
        return weeklyData;
      case "Bulanan":
      default:
        return monthlyData;
    }
  };

  return (
    <div className="bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-border/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 md:mb-6">
        <h3 className="text-sm md:text-base font-semibold text-foreground">Arus Uang</h3>
        
        <div className="flex items-center justify-between sm:justify-end gap-4 md:gap-6">
          <div className="flex items-center gap-2 md:gap-4 flex-wrap bg-muted/20 backdrop-blur-sm px-3 py-1.5 rounded-full">
            <div className="flex items-center gap-1 md:gap-1.5">
              <div className="w-2 h-2 md:w-2.5 md:h-2.5 rounded-full bg-primary shadow-lg shadow-primary/50" />
              <span className="text-xs text-muted-foreground">Masuk</span>
            </div>
            <div className="flex items-center gap-1 md:gap-1.5">
              <div className="w-2 h-2 md:w-2.5 md:h-2.5 rounded-full bg-chart-expense shadow-lg shadow-chart-expense/50" />
              <span className="text-xs text-muted-foreground">Keluar</span>
            </div>
            <div className="flex items-center gap-1 md:gap-1.5">
              <div className="w-2 h-2 md:w-2.5 md:h-2.5 rounded-full bg-chart-space shadow-lg shadow-chart-space/50" />
              <span className="text-xs text-muted-foreground">Sisa</span>
            </div>
          </div>
          
          <DropdownMenu>
            <DropdownMenuTrigger className="flex items-center gap-1 md:gap-1.5 text-xs md:text-sm text-foreground font-medium hover:text-primary transition-colors px-4 py-2 rounded-full bg-primary/10 backdrop-blur-sm border border-primary/20 hover:bg-primary/20">
              {filter}
              <ChevronDown className="w-3.5 h-3.5 md:w-4 md:h-4" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="backdrop-blur-2xl bg-card/80 border-border/30 rounded-xl shadow-2xl">
              <DropdownMenuItem onClick={() => setFilter("Harian")} className="rounded-lg">Harian</DropdownMenuItem>
              <DropdownMenuItem onClick={() => setFilter("Mingguan")} className="rounded-lg">Mingguan</DropdownMenuItem>
              <DropdownMenuItem onClick={() => setFilter("Bulanan")} className="rounded-lg">Bulanan</DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>
      
      <div className="h-[200px] md:h-[280px] bg-muted/10 backdrop-blur-sm rounded-2xl p-2">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={getData()} barGap={2} barCategoryGap="20%">
            <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border) / 0.3)" vertical={false} />
            <XAxis 
              dataKey="period" 
              axisLine={false} 
              tickLine={false} 
              tick={{ fontSize: 10, fill: "hsl(var(--muted-foreground))" }}
              interval={filter === "Bulanan" ? 1 : 0}
            />
            <YAxis hide />
            <Tooltip content={<CustomTooltip />} cursor={false} />
            <Bar dataKey="space" stackId="a" fill="hsl(var(--chart-space))" radius={[0, 0, 12, 12]} />
            <Bar dataKey="expense" stackId="a" fill="hsl(var(--chart-expense))" radius={[0, 0, 0, 0]} />
            <Bar dataKey="income" stackId="a" fill="hsl(var(--primary))" radius={[12, 12, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
};

export default MoneyFlowChart;
