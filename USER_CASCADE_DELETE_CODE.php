<?php

/**
 * ADD CASCADE DELETE TO USER MODEL
 * Code to add to User.php after the casts() method
 */

// Add this method to app/Models/User.php after line 59:

/**
 * Boot the model
 */
protected static function boot()
{
    parent::boot();

    // When user is deleted, also delete their WhatsApp number mappings
    static::deleting(function ($user) {
        // Delete WhatsApp number mappings
        \DB::table('user_whatsapp_numbers')
            ->where('user_id', $user->id)
            ->delete();
        
        // Delete user_tenants pivot entries
        \DB::table('user_tenants')
            ->where('user_id', $user->id)
            ->delete();
            
        \Log::info("Cascade deleted WhatsApp mappings for user", [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
    });
}
