import { Wallet, TrendingUp, ChevronDown } from "lucide-react";

const BalanceCard = () => {
  return (
    <div className="group bg-card/60 backdrop-blur-2xl rounded-[13px] p-3 md:p-4 border border-border/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10 hover:-translate-y-1 hover:bg-card/70 animate-fade-in-up" style={{ animationDelay: "0.1s" }}>
      <div className="flex items-center justify-between mb-2 md:mb-3">
        <h3 className="text-xs md:text-sm font-medium text-muted-foreground">Saldo Saya</h3>
        <button className="flex items-center gap-1 md:gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors bg-muted/30 backdrop-blur-sm px-2 py-1 rounded-full">
          Semua waktu
          <ChevronDown className="w-3 h-3 md:w-3.5 md:h-3.5" />
        </button>
      </div>
      
      <div className="mb-3 md:mb-4">
        <span className="amount-primary text-2xl md:text-3xl bg-gradient-to-r from-foreground to-foreground/80 bg-clip-text">Rp74.503</span>
        <span className="amount-cents text-lg md:text-xl">.000</span>
      </div>
      
      <div className="space-y-1.5 md:space-y-2">
        <div className="flex items-center gap-2 p-2 rounded-xl bg-accent/30 backdrop-blur-sm border border-accent/20 group-hover:bg-accent/40 transition-all">
          <div className="w-6 h-6 md:w-7 md:h-7 rounded-lg bg-accent/50 backdrop-blur-sm flex items-center justify-center flex-shrink-0">
            <Wallet className="w-3.5 h-3.5 md:w-4 md:h-4 text-accent-foreground" />
          </div>
          <span className="text-xs text-muted-foreground flex-1 min-w-0 truncate">Pendapatan terakhir</span>
          <span className="text-xs font-semibold text-primary flex-shrink-0">+Rp14.503.000</span>
        </div>
        
        <div className="flex items-center gap-2 p-2 rounded-xl bg-primary/5 backdrop-blur-sm border border-primary/10 group-hover:bg-primary/10 transition-all">
          <div className="w-6 h-6 md:w-7 md:h-7 rounded-lg bg-primary/20 backdrop-blur-sm flex items-center justify-center flex-shrink-0">
            <TrendingUp className="w-3.5 h-3.5 md:w-4 md:h-4 text-primary" />
          </div>
          <span className="text-xs text-muted-foreground flex-1 min-w-0">Total bonus</span>
          <span className="text-xs font-semibold text-primary flex-shrink-0">+Rp700.000</span>
        </div>
      </div>
    </div>
  );
};

export default BalanceCard;
