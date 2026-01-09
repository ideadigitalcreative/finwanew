import { ArrowRight, BarChart3 } from "lucide-react";

const RemainingMonthly = () => {
  return (
    <div className="group bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-border/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10 animate-fade-in-up" style={{ animationDelay: "0.4s" }}>
      <div className="flex items-center justify-between mb-4 md:mb-5">
        <h3 className="text-sm md:text-base font-semibold text-foreground">Sisa Bulanan</h3>
        <button className="flex items-center gap-1 md:gap-1.5 text-xs text-muted-foreground hover:text-primary transition-colors bg-muted/30 backdrop-blur-sm px-3 py-1.5 rounded-full hover:bg-primary/10">
          Atur anggaran
          <ArrowRight className="w-3 h-3 md:w-3.5 md:h-3.5" />
        </button>
      </div>
      
      <div className="flex flex-col sm:flex-row items-start gap-4 md:gap-6 mb-4 md:mb-5">
        <div className="bg-gradient-to-br from-primary/20 to-accent/30 backdrop-blur-sm rounded-2xl p-4 border border-primary/10">
          <div className="flex items-baseline">
            <span className="text-4xl md:text-5xl font-bold text-foreground">69</span>
            <span className="text-xl md:text-2xl font-medium text-muted-foreground">%</span>
          </div>
          <div className="flex items-center gap-1 md:gap-1.5 mt-2">
            <BarChart3 className="w-3.5 h-3.5 md:w-4 md:h-4 text-primary" />
            <span className="text-xs text-muted-foreground">Rata-rata</span>
            <span className="text-xs font-semibold text-primary">+2,4%</span>
          </div>
        </div>
        
        <div className="flex-1 w-full sm:w-auto">
          <p className="text-xs md:text-sm text-muted-foreground leading-relaxed mb-3">
            Keuanganmu sehat! Pengeluaran bulanan masih dalam batas aman
          </p>
          
          {/* Progress bar */}
          <div className="h-2 md:h-3 bg-muted/30 backdrop-blur-sm rounded-full overflow-hidden border border-border/20">
            <div className="h-full bg-gradient-to-r from-primary via-primary to-primary/60 rounded-full transition-all duration-500 shadow-lg shadow-primary/30" style={{ width: "69%" }} />
          </div>
        </div>
      </div>
      
      {/* Category cards */}
      <div className="grid grid-cols-3 gap-2 md:gap-3">
        {/* Needs */}
        <div className="bg-muted/20 backdrop-blur-md rounded-2xl p-3 md:p-4 transition-all duration-300 hover:bg-muted/40 border border-border/20 hover:border-primary/20 hover:shadow-lg hover:shadow-primary/5">
          <div className="flex items-baseline mb-0.5 md:mb-1">
            <span className="text-lg md:text-2xl font-bold text-foreground">89</span>
            <span className="text-xs md:text-sm font-medium text-muted-foreground">%</span>
          </div>
          <p className="text-xs text-muted-foreground">Kebutuhan</p>
          
          <div className="mt-2 md:mt-3 h-1.5 md:h-2 bg-muted/30 rounded-full overflow-hidden">
            <div className="h-full bg-gradient-to-r from-primary to-primary/70 rounded-full transition-all duration-300" style={{ width: "89%" }} />
          </div>
          
          <p className="text-xs text-muted-foreground mt-1.5 md:mt-2 font-medium">Rp7,89jt</p>
        </div>
        
        {/* Food */}
        <div className="bg-chart-expense/10 backdrop-blur-md rounded-2xl p-3 md:p-4 transition-all duration-300 hover:bg-chart-expense/20 border border-chart-expense/20 hover:border-chart-expense/40 hover:shadow-lg hover:shadow-chart-expense/10">
          <div className="flex items-baseline mb-0.5 md:mb-1">
            <span className="text-lg md:text-2xl font-bold text-foreground">78</span>
            <span className="text-xs md:text-sm font-medium text-muted-foreground">%</span>
          </div>
          <p className="text-xs text-muted-foreground">Makanan</p>
          
          <div className="mt-2 md:mt-3 h-1.5 md:h-2 bg-muted/30 rounded-full overflow-hidden">
            <div className="h-full bg-gradient-to-r from-chart-expense to-chart-expense/70 rounded-full transition-all duration-300" style={{ width: "78%" }} />
          </div>
          
          <p className="text-xs text-muted-foreground mt-1.5 md:mt-2 font-medium">Rp9,5jt</p>
        </div>
        
        {/* Education */}
        <div className="bg-accent/30 backdrop-blur-md rounded-2xl p-3 md:p-4 transition-all duration-300 hover:bg-accent/50 border border-accent/30 hover:border-accent/50 hover:shadow-lg hover:shadow-accent/10">
          <div className="flex items-baseline mb-0.5 md:mb-1">
            <span className="text-lg md:text-2xl font-bold text-foreground">42</span>
            <span className="text-xs md:text-sm font-medium text-muted-foreground">%</span>
          </div>
          <p className="text-xs text-muted-foreground">Pendidikan</p>
          
          <div className="mt-2 md:mt-3 h-1.5 md:h-2 bg-muted/30 rounded-full overflow-hidden">
            <div className="h-full bg-gradient-to-r from-primary to-accent-foreground rounded-full transition-all duration-300" style={{ width: "42%" }} />
          </div>
        </div>
      </div>
    </div>
  );
};

export default RemainingMonthly;
