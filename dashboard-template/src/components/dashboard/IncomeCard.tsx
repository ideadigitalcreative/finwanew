import { TrendingDown, TrendingUp } from "lucide-react";

const IncomeCard = () => {
  return (
    <div className="group bg-card/60 backdrop-blur-2xl rounded-[13px] p-3 md:p-4 border border-border/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10 hover:-translate-y-1 hover:bg-card/70 animate-fade-in-up" style={{ animationDelay: "0.2s" }}>
      <div className="flex items-center justify-between mb-2 md:mb-3">
        <h3 className="text-xs md:text-sm font-medium text-muted-foreground">Pemasukan Saya</h3>
        <span className="text-xs text-muted-foreground bg-muted/30 backdrop-blur-sm px-2 py-1 rounded-full">Juli 2024</span>
      </div>
      
      <div className="flex items-end justify-between mb-3 md:mb-4">
        <div>
          <span className="amount-primary text-2xl md:text-3xl">Rp101.333</span>
          <span className="amount-cents text-lg md:text-xl">.000</span>
        </div>
        
        {/* Mini bar chart */}
        <div className="flex items-end gap-0.5 md:gap-1 h-7 md:h-9 p-1.5 bg-primary/5 backdrop-blur-sm rounded-xl">
          {[40, 55, 35, 70, 45, 60, 50].map((height, i) => (
            <div 
              key={i} 
              className="w-1.5 md:w-2 rounded-full bg-primary/60 transition-all duration-300 hover:bg-primary group-hover:bg-primary/80" 
              style={{ height: `${height}%` }}
            />
          ))}
        </div>
      </div>
      
      <div className="flex items-center gap-2 md:gap-3 mb-3 md:mb-4 flex-wrap">
        <div className="flex items-center gap-1 md:gap-1.5 bg-destructive/10 backdrop-blur-sm px-2 py-0.5 rounded-full">
          <TrendingDown className="w-3 h-3 md:w-3.5 md:h-3.5 text-destructive" />
          <span className="text-xs text-muted-foreground">Min</span>
          <span className="text-xs font-medium text-destructive">-2,4%</span>
        </div>
        <div className="flex items-center gap-1 md:gap-1.5 bg-primary/10 backdrop-blur-sm px-2 py-0.5 rounded-full">
          <TrendingUp className="w-3 h-3 md:w-3.5 md:h-3.5 text-primary" />
          <span className="text-xs text-muted-foreground">Diperoleh</span>
          <span className="text-xs font-medium text-primary">+Rp458.000</span>
        </div>
      </div>
      
      <div className="grid grid-cols-3 gap-1.5 md:gap-2 pt-2 md:pt-3 border-t border-border/30">
        <div className="text-center p-1.5 rounded-lg bg-muted/20 backdrop-blur-sm group-hover:bg-muted/30 transition-all">
          <p className="text-xs text-muted-foreground">Gaji</p>
          <p className="text-xs md:text-sm font-semibold text-foreground">Rp28,3jt</p>
        </div>
        <div className="text-center p-1.5 rounded-lg bg-muted/20 backdrop-blur-sm group-hover:bg-muted/30 transition-all">
          <p className="text-xs text-muted-foreground">Bisnis</p>
          <p className="text-xs md:text-sm font-semibold text-foreground">Rp38,5jt</p>
        </div>
        <div className="text-center p-1.5 rounded-lg bg-muted/20 backdrop-blur-sm group-hover:bg-muted/30 transition-all">
          <p className="text-xs text-muted-foreground">Investasi</p>
          <p className="text-xs md:text-sm font-semibold text-foreground">Rp34,4jt</p>
        </div>
      </div>
    </div>
  );
};

export default IncomeCard;
