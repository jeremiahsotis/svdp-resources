# WP Go Maps V10
The next generation of WP Go Maps, including improved performance, a new mapping engine, refined user interface and better support for large datasets.

## Atlas Novus
With this release we are officially deprecating our **Legacy** build, and although it is still available for existing installations, all new installations will default to **Atlas Novus**. This build includes all the latest features and adds additional user experiences aimed at improving how you create maps. 

No new features will be ported to our Legacy build engine moving forward, however, the underlying code base will remain in place, with no intention of being removed in the future. 

## V9 Support
Along with this release, we will stop feature development for our V9 builds, instead focusing all feature development on V10. We will continue to maintain security and critical issues in V9  for approximately 12 months after this release. 

## Map Engine
With this release we have reworked our mapping engine system, not only adding a new engine, but also creating helpful presets which are powered by specific engines, for more use cases. 

## Performance
Building on the progress we made in the V9 release, V10 sees a significant shift to more performant mapping. Specifically in terms of large datasets, with more ways to load your data than before, allowing caching and optimization of this data in a significant way. 

## Theme Overrides
V10 will also seek to allow theme developers and 3rd developers with an integrated method for replacing our internal templates, allowing more customization than before. 

## Developer Focus
We have made significant leaps in developer support with more hooks, JavaScript overrides, and more customization options than any of our previous versions.  

## Backwards Compatibility
As with all of our previous releases, we have ensured that previous versions of our Pro, Gold and UGM add-ons are all compatible with the latest basic version. With that said, it will limit some functionality in these use cases. Specifically, new mapping engines, customization options and additional datasets may not be supported when used in this combination. 

This is true even when a feature is available in the basic V10 release, meaning a feature might be available until an old version of the Pro add-on is activated. This is because the underlying code does not exist in those versions of the Pro add-on and for that reason cannot be used. 

## PHP 8
In V9 we worked diligently to ensure PHP 8 is supported by our core code, we will continue to work on this moving forward, and it should be assumed the V10 is required for PHP 8.4 and above for the best results. Although new contributions on V9 may be made to allow minimal support for future PHP versions, it is not technically feasible to continue to maintain both versions indefinitely. 
