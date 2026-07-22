from sqlalchemy.orm import Session
from app.models.inventory import Inventory, StockMovement, MovementType
from app.models.product import Product

def get_or_create_inventory(db: Session, product_id: int) -> Inventory:
    inv = db.query(Inventory).filter(Inventory.product_id == product_id).first()
    if not inv:
        inv = Inventory(product_id=product_id, quantity=0)
        db.add(inv)
        db.flush()
    return inv

def update_stock(db: Session, product_id: int, quantity: float,
                 movement_type: MovementType, reference_type: str = None,
                 reference_id: int = None, notes: str = None,
                 user_id: int = None) -> Inventory:
    inv = get_or_create_inventory(db, product_id)
    if movement_type in [MovementType.SALE_OUT, MovementType.RETURN_OUT, MovementType.TRANSFER]:
        if inv.quantity < abs(quantity):
            raise ValueError(f"Insufficient stock for product ID {product_id}. Available: {inv.quantity}, Requested: {abs(quantity)}")
        inv.quantity -= abs(quantity)
    else:
        inv.quantity += abs(quantity)

    movement = StockMovement(
        product_id=product_id,
        quantity=quantity,
        movement_type=movement_type,
        reference_type=reference_type,
        reference_id=reference_id,
        notes=notes,
        created_by=user_id
    )
    db.add(movement)
    db.flush()
    return inv

def get_stock_level(db: Session, product_id: int) -> float:
    inv = db.query(Inventory).filter(Inventory.product_id == product_id).first()
    return inv.quantity if inv else 0

def get_low_stock_products(db: Session) -> list:
    return db.query(Product).join(Inventory).filter(
        Inventory.quantity <= Product.min_stock_level
    ).all()
