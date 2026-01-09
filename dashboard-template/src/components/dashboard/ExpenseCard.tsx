import { TrendingDown, TrendingUp, Zap } from "lucide-react";

const ExpenseCard = () => {
  return (
    <div className="group bg-card/60 backdrop-blur-2xl rounded-[13px] p-3 md:p-4 border border-border/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10 hover:-translate-y-1 hover:bg-card/70 animate-fade-in-up" style={{ animationDelay: "0.3s" }}>
      <div className="flex items-center justify-between mb-1.5">
        <span className="text-xs text-muted-foreground bg-muted/30 backdrop-blur-sm px-2 py-0.5 rounded-full">Juli 2024</span>
      </div>
      
      <div className="mb-2 md:mb-3">
        <span className="amount-primary text-xl md:text-2xl">Rp26.830</span>
        <span className="amount-cents text-base md:text-lg">.000</span>
        <p className="text-xs text-muted-foreground mt-0.5">Total pengeluaran</p>
      </div>
      
      <div className="flex items-center gap-2 md:gap-3 mb-3 md:mb-4 flex-wrap">
        <div className="flex items-center gap-1 md:gap-1.5 bg-muted/30 backdrop-blur-sm px-2 py-0.5 rounded-full">
          <TrendingDown className="w-3 h-3 md:w-3.5 md:h-3.5 text-muted-foreground" />
          <span className="text-xs text-muted-foreground">Min</span>
          <span className="text-xs font-medium text-primary">7,4%</span>
        </div>
        <div className="flex items-center gap-1 md:gap-1.5 bg-primary/10 backdrop-blur-sm px-2 py-0.5 rounded-full">
          <TrendingUp className="w-3 h-3 md:w-3.5 md:h-3.5 text-primary" />
          <span className="text-xs text-muted-foreground">Hemat</span>
          <span className="text-xs font-medium text-primary">+Rp800.000</span>
        </div>
      </div>
      
      {/* Scale indicator */}
      <div className="flex items-center justify-between text-xs text-muted-foreground mb-1.5 px-1">
        <span>0</span>
        <span>50</span>
        <span>100</span>
      </div>
      
      {/* Progress bar with markers */}
      <div className="relative h-2.5 md:h-3 bg-muted/30 backdrop-blur-sm rounded-full overflow-hidden mb-2 md:mb-3 border border-border/20">
        <div 
          className="absolute top-0 left-0 h-full bg-gradient-to-r from-primary/60 via-primary to-chart-expense rounded-full transition-all duration-500 group-hover:from-primary/80"
          style={{ width: "75%" }}
        />
        {/* Marker lines */}
        <div className="absolute top-0 left-0 w-full h-full flex">
          {[...Array(20)].map((_, i) => (
            <div key={i} className="flex-1 border-r border-background/10 last:border-r-0" />
          ))}
        </div>
      </div>
      
      <div className="text-center bg-accent/20 backdrop-blur-sm rounded-lg p-1.5 border border-accent/20">
        <p className="text-xs text-muted-foreground">Juli 2024</p>
        <p className="text-xs text-foreground font-medium flex items-center justify-center gap-1">
          Target tercapai 75% <Zap className="w-3 h-3 text-warning" />
        </p>
      </div>
    </div>
  );
};

export default ExpenseCard;
